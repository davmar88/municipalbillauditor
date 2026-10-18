<?php

namespace App\Http\Controllers\Game;

use App\Game\Levels;
use App\Game\Players;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Player;
use App\Models\RosetteEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Your animal: level, Rosettes, collection and the story so far. */
class ProfileController extends Controller
{
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
                'seasonRosettes' => $season ? (int) RosetteEntry::query()->where('player_id', $player->id)->where('season_id', $season->id)->sum('amount') : 0,
                'wanted' => $player->wanted,
                'joined' => $player->created_at->toIso8601String(),
                'faction' => $player->faction?->name,
                'role_in_faction' => $player->faction_role?->label(),
                'orchardUntil' => $player->inOrchard() ? $player->orchard_until->toIso8601String() : null,
            ],
            'biography' => $this->biography($player),
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

    public function language(Request $request, Players $players): RedirectResponse
    {
        $language = (string) $request->validate(['language' => ['required', 'string', 'max:30']])['language'];
        $players->setLanguage($request->user()->player, $language);

        return $this->told("Chat translations will be in {$language}.");
    }

    private function biography(Player $player): string
    {
        $exposes = $player->rosetteEntries()->where('reference', 'like', 'expose:%')->count();
        $battles = $player->rosetteEntries()->where('reference', 'like', 'battle:%')->count();
        $sales = $player->rosetteEntries()->where('reference', 'like', 'sale:%')->count();

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
