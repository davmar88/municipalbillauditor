<?php

namespace App\Game;

use App\Enums\BuildingKind;
use App\Models\Building;
use App\Models\BuildingHelp;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Faction bases. A faction raises buildings on land it holds, paid from its
 * treasury and a store of materials its members give. The Den comes first:
 * it is the faction's base, and raiders who take its region carry off a
 * fifth of the treasury. Every building falls with the land under it.
 */
final class Bases
{
    public const MATERIALS = ['wood', 'straw', 'stone', 'clay', 'wool', 'dye', 'eggs', 'honey', 'herbs', 'iron', 'gold', 'copper', 'pears', 'carrots', 'berries', 'pumpkins'];

    /**
     * Give Corn or materials to your faction's store.
     *
     * @throws GameException
     */
    public function give(Player $player, string $what, int $amount): void
    {
        if ($what !== 'corn' && $what !== 'apples' && ! in_array($what, self::MATERIALS, true)) {
            throw new GameException('Pick Corn, Apples or one of the materials to give.');
        }

        if ($amount < 1) {
            throw new GameException('Give at least 1.');
        }

        DB::transaction(function () use ($player, $what, $amount) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $faction = $player->faction_id !== null ? Faction::query()->lockForUpdate()->find($player->faction_id) : null;

            if ($faction === null) {
                throw new GameException('Join a faction to give to its store.');
            }

            if ($what === 'apples') {
                // The House spends its store of Apples on warrant bounties.
                Apples::spend($player, $amount, "Gave to {$faction->name}'s store", "give:{$faction->id}:".Str::uuid());
                $faction->apples += $amount;
            } elseif ($what === 'corn') {
                if ($player->corn < $amount) {
                    throw new GameException("You have {$player->corn} Corn.");
                }

                self::ensureRoom($faction, 'corn', $amount);
                $player->corn -= $amount;
                $faction->treasury += $amount;
            } else {
                if ($player->material($what) < $amount) {
                    throw new GameException("You have {$player->material($what)} {$what}.");
                }

                self::ensureRoom($faction, $what, $amount);

                $player->materials = [...$player->materials, $what => $player->material($what) - $amount];
                $faction->materials = [...($faction->materials ?? []), $what => $faction->material($what) + $amount];
            }

            $player->save();
            $faction->save();
            Missions::record($player, 'give', $amount);
        });
    }

    /**
     * Raise a building on a region the faction holds. Only the leader and
     * council build.
     *
     * @throws GameException
     */
    public function build(Player $player, Region $region, BuildingKind $kind): Building
    {
        return DB::transaction(function () use ($player, $region, $kind) {
            $faction = $this->builder($player);
            $region = Region::query()->lockForUpdate()->findOrFail($region->id);

            if ($region->valley_id !== $faction->valley_id || $region->faction_id !== $faction->id) {
                throw new GameException("Your faction doesn't hold {$region->name}. Build on land you hold.");
            }

            if ($region->isSafe() || $region->isCity() || $region->isScrub()) {
                throw new GameException("Nothing can be built in {$region->name}.");
            }

            if ($region->isFarmhouse() !== ($kind === BuildingKind::HeadOffice)) {
                throw new GameException($kind === BuildingKind::HeadOffice
                    ? 'A Head Office goes in the Farmhouse, where the valley is ruled from.'
                    : 'Only a Head Office can be built in the Farmhouse.');
            }

            // What the last holders built here is a ruin now.
            Building::query()->where('region_id', $region->id)->where('faction_id', '!=', $faction->id)->delete();

            if (Building::query()->where('region_id', $region->id)->exists()) {
                throw new GameException("{$region->name} already has a building. Raise it a level instead.");
            }

            $hasDen = self::den($faction) !== null;

            if ($kind === BuildingKind::Den && $hasDen) {
                throw new GameException('Your faction already has its Den. A faction has one base.');
            }

            if ($kind !== BuildingKind::Den && $kind !== BuildingKind::HeadOffice && ! $hasDen) {
                throw new GameException("Raise a Den first: it's your faction's base.");
            }

            $this->pay($faction, $kind->cost(1));

            $building = Building::create([
                'faction_id' => $faction->id,
                'region_id' => $region->id,
                'kind' => $kind,
                'level' => 1,
                'ready_at' => CarbonImmutable::now()->addMinutes($kind->minutes(1)),
            ]);

            $this->exposed($region, $faction, $building);

            return $building;
        });
    }

    /** No more than this many can build at once. */
    public const MOST_BUILDERS = 6;

    /** How fast a building goes up with this many building it: each extra pair of hands adds half again. */
    public static function pace(int $builders): float
    {
        return 1 + 0.5 * (max(1, min($builders, self::MOST_BUILDERS)) - 1);
    }

    /**
     * A member lends a hand with their faction's building going up, where
     * it stands. What is left of the work goes faster with every helper.
     *
     * @throws GameException
     */
    public function help(Player $player, Building $building): Building
    {
        return DB::transaction(function () use ($player, $building) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $building = Building::query()->with(['region', 'faction'])->lockForUpdate()->findOrFail($building->id);
            $name = $building->kind->label();

            if ($player->faction_id !== $building->faction_id) {
                throw new GameException("Only {$building->faction->name}'s members can help build its {$name}.");
            }

            if ($building->isReady()) {
                throw new GameException("The {$name} is already standing.");
            }

            Movement::ensureStandingIn($player, $building->region, 'help build');

            if (BuildingHelp::query()->where('building_id', $building->id)->where('player_id', $player->id)->where('level', $building->level)->exists()) {
                throw new GameException("You're already helping build the {$name}.");
            }

            $builders = $building->builders();

            if ($builders >= self::MOST_BUILDERS) {
                throw new GameException("{$builders} are building the {$name} already: there's no room for more hands.");
            }

            Horseshoes::spend($player, 1);
            BuildingHelp::create(['building_id' => $building->id, 'player_id' => $player->id, 'level' => $building->level]);

            $left = max(0, CarbonImmutable::now()->diffInSeconds($building->ready_at));
            $building->forceFill([
                'ready_at' => CarbonImmutable::now()->addSeconds((int) ceil($left * self::pace($builders) / self::pace($builders + 1))),
            ])->save();

            Rosettes::award($player, 3, "Helped build the {$name}", "build:{$building->id}:{$building->level}:{$player->id}", $player->valley->currentSeason());

            return $building;
        });
    }

    /**
     * Raise a building a level. It keeps working at its old level while the
     * new one goes up.
     *
     * @throws GameException
     */
    public function upgrade(Player $player, Building $building): Building
    {
        return DB::transaction(function () use ($player, $building) {
            $faction = $this->builder($player);
            $building = Building::query()->lockForUpdate()->findOrFail($building->id);
            $region = $building->region;
            $name = $building->kind->label();

            if ($building->faction_id !== $faction->id) {
                throw new GameException("That isn't your faction's {$name}.");
            }

            if ($region->faction_id !== $faction->id) {
                throw new GameException("Your faction no longer holds {$region->name}.");
            }

            if (! $building->isReady()) {
                throw new GameException("The {$name} is still going up. It's ready at ".Clock::local($building->ready_at)->format('H:i').'.');
            }

            if ($building->level >= BuildingKind::MAX_LEVEL) {
                throw new GameException("The {$name} is as big as it gets.");
            }

            if ($building->kind !== BuildingKind::Den && $building->kind !== BuildingKind::HeadOffice && self::den($faction) === null) {
                throw new GameException('Your faction needs a Den before it can build.');
            }

            $level = $building->level + 1;
            $this->pay($faction, $building->kind->cost($level));
            $building->forceFill([
                'level' => $level,
                'ready_at' => CarbonImmutable::now()->addMinutes($building->kind->minutes($level)),
            ])->save();

            $this->exposed($region, $faction, $building);

            return $building;
        });
    }

    /**
     * Raiders took a region: its building falls, and they carry off what it
     * stored, as much as their own store has room for.
     */
    public function fall(Region $region, ?int $takenBy): void
    {
        $building = Building::query()->where('region_id', $region->id)->lockForUpdate()->first();

        if ($building === null || $building->faction_id === $takenBy) {
            return;
        }

        $losers = Faction::query()->lockForUpdate()->find($building->faction_id);
        $winners = $takenBy !== null ? Faction::query()->lockForUpdate()->find($takenBy) : null;

        if ($losers !== null && $winners !== null) {
            ['corn' => $corn, 'materials' => $materials] = Stores::emptyOut($losers, $building);
            Stores::add($winners, $corn, $materials);

            if ($corn > 0 || $materials !== []) {
                $what = $corn.' Corn'.($materials !== [] ? ' and '.array_sum($materials).' materials' : '');
                $map = route('play', [], false);
                Notices::send(Notices::members($winners->id), 'battle', "📦 Your raiders carried off {$what} from {$losers->name}'s {$building->kind->label()} in {$region->name}.", $map);
                Notices::send(Notices::members($losers->id), 'battle', "📦 {$winners->name} carried off {$what} from your {$building->kind->label()} in {$region->name}.", $map);
            }
        }

        $building->delete();
    }

    /**
     * @throws GameException
     */
    private static function ensureRoom(Faction $faction, string $what, int $amount): void
    {
        $room = Stores::room($faction, $what);

        if ($amount > $room) {
            $name = $what === 'corn' ? 'Corn' : $what;

            throw new GameException("The store has room for {$room} more {$name}. Raise a Den or a Storehouse, or a bigger one, to store more.");
        }
    }

    /**
     * A faction busy building drops its guard: whatever peace the region had
     * after changing hands ends, and it can be raided while the work goes
     * on. The valley hears when a Head Office starts going up.
     */
    private function exposed(Region $region, Faction $faction, Building $building): void
    {
        $region->forceFill(['settled_until' => null])->save();

        if ($building->kind === BuildingKind::HeadOffice && $building->level === 1) {
            Notices::send(Notices::valley($region->valley_id), 'house', "🏛 {$faction->name} is building a Head Office in the Farmhouse. It rules Hollowmere once it stands at ".Clock::local($building->ready_at)->format('H:i').', unless someone takes the Farmhouse first.', route('play', [], false));
        }
    }

    /**
     * A finished Head Office makes its faction the ruler: the House. The
     * valley hears it once, and a new House gets three extra law changes.
     */
    public function crown(Valley $valley): void
    {
        $farmhouse = $valley->region('farmhouse');

        if ($farmhouse->faction_id === null) {
            return;
        }

        $office = Building::query()
            ->where('region_id', $farmhouse->id)
            ->where('faction_id', $farmhouse->faction_id)
            ->where('kind', BuildingKind::HeadOffice)
            ->whereNull('crowned_at')
            ->first();

        if ($office === null || $office->workingLevel() < 1) {
            return;
        }

        $office->forceFill(['crowned_at' => CarbonImmutable::now()])->save();
        $season = $valley->currentSeason();
        $season?->forceFill(['house_since' => CarbonImmutable::now(), 'bonus_law_changes' => $season->bonus_law_changes + 3])->save();
        $name = Faction::query()->whereKey($farmhouse->faction_id)->value('name');
        Notices::send(Notices::valley($valley->id), 'house', "👑 {$name} finished its Head Office in the Farmhouse and now rules Hollowmere.", route('play', [], false));
    }

    /** Clear away buildings on land their faction no longer holds. */
    public function clearRuins(Valley $valley): void
    {
        $ruins = Building::query()
            ->with('region:id,valley_id,faction_id')
            ->get()
            ->filter(fn (Building $building) => $building->region->valley_id === $valley->id
                && $building->region->faction_id !== $building->faction_id);

        if ($ruins->isNotEmpty()) {
            Building::query()->whereKey($ruins->modelKeys())->delete();
        }
    }

    /** The faction's Den, if it still stands. */
    public static function den(Faction $faction): ?Building
    {
        return self::standing($faction)->firstWhere('kind', BuildingKind::Den);
    }

    /**
     * The faction's buildings on land it still holds.
     *
     * @return Collection<int, Building>
     */
    public static function standing(Faction $faction): Collection
    {
        return Building::query()
            ->with('region')
            ->where('faction_id', $faction->id)
            ->whereHas('region', fn ($query) => $query->where('faction_id', $faction->id))
            ->get();
    }

    /** How much harder a region defends, from a Den or Watchtower its holders built. */
    public static function defence(Region $region): float
    {
        $building = Building::query()->where('region_id', $region->id)->where('faction_id', $region->faction_id)->first();

        return $building && in_array($building->kind, [BuildingKind::Den, BuildingKind::Watchtower, BuildingKind::HeadOffice], true)
            ? 1 + $building->bonus()
            : 1.0;
    }

    /** How much harder a faction's raid hits, from its best Drill Yard next to the target. */
    public static function attack(int $factionId, Region $target): float
    {
        $next = $target->neighbours()->where('faction_id', $factionId)->pluck('regions.id');
        $best = Building::query()
            ->where('faction_id', $factionId)
            ->where('kind', BuildingKind::DrillYard)
            ->whereIn('region_id', $next)
            ->get()
            ->max(fn (Building $building) => $building->bonus());

        return 1 + (float) ($best ?? 0);
    }

    /** The share of a faction's treasury a looter gets away with. */
    public static function lootShare(Faction $faction): float
    {
        $store = self::standing($faction)
            ->where('kind', BuildingKind::Storehouse)
            ->filter(fn (Building $building) => $building->workingLevel() >= 1)
            ->max(fn (Building $building) => $building->workingLevel());

        return $store ? BuildingKind::Storehouse->lootShare($store) : Crimes::LOOT_SHARE;
    }

    /** How many more materials an animal gathers where its faction has a Workshop. */
    public static function gathering(Player $player, Region $region): float
    {
        if ($player->faction_id === null || $region->faction_id !== $player->faction_id) {
            return 1.0;
        }

        $workshop = Building::query()
            ->where('region_id', $region->id)
            ->where('faction_id', $player->faction_id)
            ->where('kind', BuildingKind::Workshop)
            ->first();

        return 1 + ($workshop?->bonus() ?? 0.0);
    }

    /** How many members a faction can have: more with a bigger Den. */
    public static function memberCap(Faction $faction): int
    {
        $den = self::den($faction);

        return (int) config('game.faction.max_members') + ($den ? BuildingKind::Den->extraMembers($den->workingLevel()) : 0);
    }

    /**
     * The faction of a player who may build: its leader or council.
     *
     * @throws GameException
     */
    private function builder(Player $player): Faction
    {
        $player = Players::lock($player);
        Players::ensureFree($player);

        if ($player->faction_id === null) {
            throw new GameException('Join a faction to build.');
        }

        if (! $player->decides()) {
            throw new GameException("Only your faction's leader and council build. Give materials to the store instead.");
        }

        return Faction::query()->lockForUpdate()->findOrFail($player->faction_id);
    }

    /**
     * Pay for a level out of the faction's treasury and store, or say what is missing.
     *
     * @param  array{corn: int, materials: array<string, int>}  $cost
     *
     * @throws GameException
     */
    public function pay(Faction $faction, array $cost): void
    {
        $short = [];

        if ($faction->treasury < $cost['corn']) {
            $short[] = ($cost['corn'] - $faction->treasury).' Corn';
        }

        foreach ($cost['materials'] as $material => $amount) {
            if ($faction->material($material) < $amount) {
                $short[] = ($amount - $faction->material($material))." {$material}";
            }
        }

        if ($short !== []) {
            $list = count($short) > 1 ? implode(', ', array_slice($short, 0, -1)).' and '.end($short) : $short[0];

            throw new GameException("The store is short of {$list}. Members can give from the Faction page.");
        }

        $materials = $faction->materials ?? [];

        foreach ($cost['materials'] as $material => $amount) {
            $materials[$material] = $faction->material($material) - $amount;
        }

        $faction->forceFill([
            'treasury' => $faction->treasury - $cost['corn'],
            'materials' => $materials,
        ])->save();
    }
}
