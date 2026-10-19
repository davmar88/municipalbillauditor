<?php

namespace App\Http\Controllers\Game;

use App\Game\LawTemplate;
use App\Game\Levels;
use App\Game\Messages;
use App\Game\Players;
use App\Game\Skills;
use App\Game\Wall;
use App\Game\Wardrobe;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Listing;
use App\Models\Player;
use App\Models\RosetteEntry;
use App\Models\Season;
use App\Models\Warrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Your animal: level, Rosettes, collection and the story so far. Other animals show their own, without what they hold. */
class ProfileController extends Controller
{
    /** How many items of another animal's collection its profile shows. */
    private const SHOWN = 48;

    public function show(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $season = $player->valley->currentSeason();
        $level = $player->level();

        $items = $player->items()->with('design')->get();
        $sets = $items->groupBy(fn (Item $item) => $item->design->template->value)->count();

        return Inertia::render('game/me', [
            'animal' => [
                'name' => $player->name,
                'species' => $player->species->value,
                'speciesLabel' => $player->species->label(),
                'role' => $player->species->role(),
                'talent' => $player->species->talent(),
                'weakSpot' => $player->species->weakSpot(),
                'level' => $level,
                'rosettes' => $player->rosettes,
                'levelFrom' => Levels::threshold($level),
                'nextLevelAt' => Levels::threshold($level + 1),
                'seasonRosettes' => $this->seasonRosettes($player, $season),
                'wanted' => $player->wanted,
                'joined' => $player->created_at->toIso8601String(),
                'faction' => $player->faction?->name,
                'role_in_faction' => $player->faction_role?->label(),
                'orchardUntil' => $player->inOrchard() ? $player->orchard_until->toIso8601String() : null,
            ],
            'wearing' => Wardrobe::worn($player),
            'biography' => $this->biography($player, $this->record($player)),
            'rosettes' => RosetteEntry::query()->where('player_id', $player->id)->latest('id')->limit(12)->get(['amount', 'reason', 'created_at'])
                ->map(fn (RosetteEntry $entry) => ['amount' => $entry->amount, 'reason' => $entry->reason, 'at' => $entry->created_at->toIso8601String()]),
            'collection' => [
                'items' => $items->map(fn (Item $item) => ['id' => $item->id, 'serial' => $item->serial, 'design' => $item->design->toCard()]),
                'kinds' => $sets,
            ],
            'languages' => Players::LANGUAGES,
            'language' => $player->language,
        ]);
    }

    /**
     * Another animal in your valley: who it is, its level, skills and record,
     * its shop and its collection. Never what it holds or where it stands.
     */
    public function animal(Request $request, Player $player): Response|RedirectResponse
    {
        /** @var Player $me */
        $me = $request->user()->player;

        if ($me->is($player)) {
            return to_route('play.me');
        }

        abort_if($me->valley_id !== $player->valley_id, 404);

        $player->load('faction:id,name,colour');
        $season = $player->valley->currentSeason();
        $level = $player->level();
        $record = $this->record($player);

        // The levy goes to the House, so without one nobody charges it.
        $levy = $season && $player->valley->house() !== null ? Wall::law($season, LawTemplate::MarketLevy) : null;

        // What is on the shelves shows in the shop, so the collection leaves it out.
        $items = $player->items()->whereDoesntHave('openListing');

        return Inertia::render('game/animal', [
            'animal' => [
                ...Messages::card($player),
                'speciesLabel' => $player->species->label(),
                'role' => $player->species->role(),
                'talent' => $player->species->talent(),
                'weakSpot' => $player->species->weakSpot(),
                'factionRole' => $player->faction_role?->label(),
                'level' => $level,
                'rosettes' => $player->rosettes,
                'levelFrom' => Levels::threshold($level),
                'nextLevelAt' => Levels::threshold($level + 1),
                'seasonRosettes' => $this->seasonRosettes($player, $season),
                'joined' => $player->created_at->toIso8601String(),
                'inPoundUntil' => $player->inPound() ? $player->pound_until->toIso8601String() : null,
                'homeClosed' => $player->homeIsClosed(),
            ],
            'biography' => $this->biography($player, $record),
            'wearing' => Wardrobe::worn($player),
            'skills' => array_map(fn (string $skill) => Skills::card($player, $skill), Skills::ALL),
            'record' => $record,
            'bounty' => $season ? Warrant::query()->where('season_id', $season->id)->where('player_id', $player->id)->where('status', 'open')->value('bounty') : null,
            'shop' => [
                'listings' => Listing::query()->with('item.design')->where('player_id', $player->id)->whereNull('sold_at')->latest('id')->get()
                    ->map(fn (Listing $listing) => [
                        'id' => $listing->id,
                        'price' => $listing->price,
                        'currency' => $listing->currency,
                        'serial' => $listing->item->serial,
                        'design' => $listing->item->design->toCard(),
                    ]),
                'levy' => $levy?->value ?? 0,
            ],
            'collection' => [
                'items' => (clone $items)->with('design')->latest('id')->limit(self::SHOWN)->get()
                    ->map(fn (Item $item) => ['id' => $item->id, 'serial' => $item->serial, 'design' => $item->design->toCard()]),
                'count' => $items->count(),
            ],
        ]);
    }

    public function language(Request $request, Players $players): RedirectResponse
    {
        $language = (string) $request->validate(['language' => ['required', 'string', 'max:30']])['language'];
        $players->setLanguage($request->user()->player, $language);

        return $this->told("Chat translations will be in {$language}.");
    }

    private function seasonRosettes(Player $player, ?Season $season): int
    {
        return $season ? (int) RosetteEntry::query()->where('player_id', $player->id)->where('season_id', $season->id)->sum('amount') : 0;
    }

    /**
     * What the animal has done. Most of it is counted from Rosettes, which come
     * once for each win, catch and mission; sales come from the shop, since the
     * Rosettes for them stop after 60 a day.
     *
     * @return array{battles: int, gates: int, arrests: int, exposes: int, missions: int, sales: int, designs: int, caught: int}
     */
    private function record(Player $player): array
    {
        $count = fn (string $kind) => $player->rosetteEntries()->where('reference', 'like', "{$kind}:%")->count();

        return [
            'battles' => $count('battle'),
            'gates' => $count('gate'),
            'arrests' => $count('arrest'),
            'exposes' => $count('expose'),
            'missions' => $count('mission'),
            'sales' => Listing::query()->where('player_id', $player->id)->whereNotNull('sold_at')->count(),
            'designs' => $player->designs()->where('status', 'approved')->count(),
            'caught' => $player->wanted,
        ];
    }

    /** @param array{battles: int, exposes: int, sales: int} $record */
    private function biography(Player $player, array $record): string
    {
        ['exposes' => $exposes, 'battles' => $battles, 'sales' => $sales] = $record;

        $parts = ["{$player->name} the {$player->species->label()}."];

        if ($player->faction !== null) {
            $parts[] = "{$player->faction_role?->label()} of {$player->faction->name}.";
        } else {
            $parts[] = 'A lone wolf.';
        }

        if ($exposes > 0) {
            $parts[] = 'Caught '.$exposes.' secret '.str('edit')->plural($exposes).'.';
        }

        if ($battles > 0) {
            $parts[] = 'Won '.$battles.' '.str('battle')->plural($battles).'.';
        }

        if ($sales > 0) {
            $parts[] = 'Sold '.$sales.' '.str('item')->plural($sales).'.';
        }

        if ($player->wanted > 0) {
            $parts[] = 'Known to the Night Watch.';
        }

        return implode(' ', $parts);
    }
}
