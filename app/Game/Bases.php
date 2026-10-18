<?php

namespace App\Game;

use App\Enums\BuildingKind;
use App\Models\Building;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Faction bases. A faction raises buildings on land it holds, paid from its
 * treasury and a store of materials its members give. The Den comes first:
 * it is the faction's base, and raiders who take its region carry off a
 * fifth of the treasury. Every building falls with the land under it.
 */
final class Bases
{
    public const MATERIALS = ['wood', 'straw', 'stone', 'clay', 'wool', 'dye', 'eggs', 'honey', 'herbs', 'iron'];

    /** The share of the treasury raiders carry off when they take a Den. */
    public const DEN_PLUNDER = 0.20;

    /**
     * Give Corn or materials to your faction's store.
     *
     * @throws GameException
     */
    public function give(Player $player, string $what, int $amount): void
    {
        if ($what !== 'corn' && ! in_array($what, self::MATERIALS, true)) {
            throw new GameException('Pick Corn or one of the materials to give.');
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

            if ($what === 'corn') {
                if ($player->corn < $amount) {
                    throw new GameException("You have {$player->corn} Corn.");
                }

                $player->corn -= $amount;
                $faction->treasury += $amount;
            } else {
                if ($player->material($what) < $amount) {
                    throw new GameException("You have {$player->material($what)} {$what}.");
                }

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

            if ($region->isSafe() || $region->isFarmhouse()) {
                throw new GameException("Nothing can be built in {$region->name}.");
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

            if ($kind !== BuildingKind::Den && ! $hasDen) {
                throw new GameException("Raise a Den first: it's your faction's base.");
            }

            $this->pay($faction, $kind->cost(1));

            return Building::create([
                'faction_id' => $faction->id,
                'region_id' => $region->id,
                'kind' => $kind,
                'level' => 1,
                'ready_at' => CarbonImmutable::now()->addMinutes($kind->minutes(1)),
            ]);
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

            if ($building->kind !== BuildingKind::Den && self::den($faction) === null) {
                throw new GameException('Your faction needs a Den before it can build.');
            }

            $level = $building->level + 1;
            $this->pay($faction, $building->kind->cost($level));
            $building->forceFill([
                'level' => $level,
                'ready_at' => CarbonImmutable::now()->addMinutes($building->kind->minutes($level)),
            ])->save();

            return $building;
        });
    }

    /**
     * Raiders took a region: its building falls, and if it was a Den they
     * carry off a fifth of the losers' treasury.
     */
    public function fall(Region $region, ?int $takenBy): void
    {
        $building = Building::query()->where('region_id', $region->id)->lockForUpdate()->first();

        if ($building === null || $building->faction_id === $takenBy) {
            return;
        }

        if ($building->kind === BuildingKind::Den && $takenBy !== null) {
            $losers = Faction::query()->lockForUpdate()->find($building->faction_id);
            $plunder = $losers ? (int) floor($losers->treasury * self::DEN_PLUNDER) : 0;

            if ($plunder > 0) {
                $losers->decrement('treasury', $plunder);
                Faction::query()->whereKey($takenBy)->increment('treasury', $plunder);
            }
        }

        $building->delete();
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

        return $building && in_array($building->kind, [BuildingKind::Den, BuildingKind::Watchtower], true)
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
