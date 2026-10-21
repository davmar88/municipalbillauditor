<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Enums\Study;
use App\Models\Effect;
use App\Models\Faction;
use App\Models\Item;
use App\Models\Player;
use App\Models\SpyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spying with a Spyglass, a weapon a faction unlocks with Technology. Looked
 * through on another faction's page, it is used up and shows what that
 * faction keeps to itself: its store, how strong it is and its weapons. The
 * spy's whole faction can read the report for a day; the faction spied on
 * never knows.
 */
final class Spying
{
    /** How long a report stays on the faction's page for the spy's faction. */
    public const HOURS = 24;

    /** What each kind of weapon power is called, for weapons already drawn. */
    private const DRAWN = [
        'pitchfork' => 'Pitchfork',
        'cudgel' => 'Cudgel',
        'sling' => 'Sling',
        'sword' => 'Iron Sword',
        'crossbow' => 'Crossbow',
        'ram' => 'Battering Ram',
        'shoes' => 'Iron Shoes',
    ];

    /** Faction-wide powers a spy can see in effect. */
    private const FACTION_POWERS = [
        'banner' => 'War Banner',
        'bell' => 'Watch Bell',
        'basket' => 'Feast Basket',
        'plough' => 'Iron Plough',
        'hedge' => 'Thorn Hedge',
        'gate' => 'Iron Gate',
    ];

    /**
     * Look through a Spyglass at a faction.
     *
     * @throws GameException
     */
    public function spy(Player $player, Faction $target): SpyReport
    {
        if ($target->valley_id !== $player->valley_id) {
            throw new GameException('That faction is in another valley.');
        }

        if ($target->id === $player->faction_id) {
            throw new GameException('That is your own faction. Spy on another one.');
        }

        return DB::transaction(function () use ($player, $target) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            $glass = self::spyglass($player) ?? throw new GameException('You need a Spyglass. Factions that study Technology can make them in the Workshop.');
            Item::query()->lockForUpdate()->findOrFail($glass->id)->forceFill(['used_at' => CarbonImmutable::now()])->save();

            $report = SpyReport::create([
                'player_id' => $player->id,
                'faction_id' => $player->faction_id,
                'target_faction_id' => $target->id,
                'report' => self::look($target),
            ]);

            if ($player->faction_id !== null) {
                Notices::send(
                    Notices::members($player->faction_id)->reject(fn (int $id) => $id === $player->id),
                    'study',
                    "🔭 {$player->name} spied on {$target->name}. Read what they saw on its page.",
                    route('play.factions.show', $target, false),
                );
            }

            return $report;
        });
    }

    /** One of the animal's Spyglasses, unused and off the shelves, if it has one. */
    public static function spyglass(Player $player): ?Item
    {
        return Item::query()
            ->where('player_id', $player->id)
            ->whereNull('used_at')
            ->whereHas('design', fn ($design) => $design->where('template', ItemTemplate::Spyglass->value))
            ->whereDoesntHave('openListing')
            ->oldest('id')
            ->first();
    }

    /** How many Spyglasses the animal has ready. */
    public static function spyglasses(Player $player): int
    {
        return Item::query()
            ->where('player_id', $player->id)
            ->whereNull('used_at')
            ->whereHas('design', fn ($design) => $design->where('template', ItemTemplate::Spyglass->value))
            ->count();
    }

    /** The newest report the animal, or its faction, has on a faction, while it's fresh. */
    public static function latest(Player $player, Faction $target): ?SpyReport
    {
        return SpyReport::query()
            ->with('player:id,name')
            ->where('target_faction_id', $target->id)
            ->where('created_at', '>', CarbonImmutable::now()->subHours(self::HOURS))
            ->where(fn ($query) => $query->where('player_id', $player->id)
                ->when($player->faction_id !== null, fn ($own) => $own->orWhere('faction_id', $player->faction_id)))
            ->latest('id')
            ->first();
    }

    /**
     * What a spy sees of a faction right now.
     *
     * @return array<string, mixed>
     */
    public static function look(Faction $faction): array
    {
        $members = $faction->members()->get();
        $ids = $members->pluck('id');
        $horseshoes = $members->sum(fn (Player $member) => Horseshoes::current($member));

        $weapons = Item::query()
            ->with('design:id,template')
            ->whereIn('player_id', $ids)
            ->whereNull('used_at')
            ->get()
            ->map(fn (Item $item) => $item->design->template)
            ->filter(fn (ItemTemplate $template) => $template->isWeapon())
            ->countBy(fn (ItemTemplate $template) => $template->label())
            ->sortDesc();

        $drawn = Effect::query()
            ->whereIn('player_id', $ids)
            ->whereIn('kind', array_keys(self::DRAWN))
            ->where('charges', '>', 0)
            ->get()
            ->groupBy('kind')
            ->map(fn ($effects, string $kind) => ['label' => self::DRAWN[$kind], 'uses' => (int) $effects->sum('charges')])
            ->values();

        $powers = Effect::query()
            ->active()
            ->where('faction_id', $faction->id)
            ->whereIn('kind', array_keys(self::FACTION_POWERS))
            ->get()
            ->map(fn (Effect $effect) => self::FACTION_POWERS[$effect->kind].($effect->expires_at !== null ? ' until '.Clock::local($effect->expires_at)->format('H:i') : ''))
            ->unique()
            ->values();

        return [
            'store' => [
                'corn' => (int) $faction->treasury,
                'apples' => (int) $faction->apples,
                'materials' => collect($faction->materials ?? [])->filter(fn ($amount) => $amount > 0)->map(fn ($amount) => (int) $amount)->sortDesc()->all(),
            ],
            'strength' => [
                'members' => $members->count(),
                'horseshoes' => (int) $horseshoes,
                // Every member's horseshoes thrown in, by species and studies: a rough measure of a raid or a defence.
                'attack' => (int) round($members->sum(fn (Player $member) => Horseshoes::current($member) * $member->species->attack()) * Research::attack($faction->id)),
                'defence' => (int) round($members->sum(fn (Player $member) => Horseshoes::current($member) * $member->species->defence()) * Research::defence($faction->id)),
                'technology' => Research::level($faction, Study::Technology),
            ],
            'weapons' => $weapons->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])->values()->all(),
            'drawn' => $drawn->all(),
            'powers' => $powers->all(),
        ];
    }
}
