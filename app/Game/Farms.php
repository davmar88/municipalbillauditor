<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The farms beyond Hollowmere's fences. A faction buys an unclaimed farm
 * outright from its treasury, or takes a held one in a raid like any other
 * land. Every farm pays its holder Corn each half hour.
 */
final class Farms
{
    public const PRICE = 300;

    public const INCOME = 5;

    /** Water it takes to water a farm, and how long it lasts; it can be watered up to two days ahead. */
    public const WATER = 10;

    public const WATER_HOURS = 12;

    public const WATER_AHEAD_HOURS = 48;

    /** How far a trench can run, in regions from the water, and what it takes per stretch from the treasury and store. */
    public const TRENCH_REACH = 4;

    public const TRENCH_CORN = 80;

    public const TRENCH_WOOD = 15;

    public const TRENCH_STONE = 15;

    /** Horseshoes it takes a rival to fill one in. */
    public const FILL = 3;

    /** How many times a farm can grow into the wild land round it. */
    public const GROWTH = 3;

    /**
     * What a farm's next growth costs from the treasury and store: the later
     * ones need the farm's own crop.
     *
     * @return array{corn: int, materials: array<string, int>}
     */
    public static function growCost(int $step, string $crop): array
    {
        return match ($step) {
            1 => ['corn' => 200, 'materials' => ['wood' => 20, 'stone' => 15]],
            2 => ['corn' => 350, 'materials' => ['wood' => 30, 'stone' => 20, $crop => 10]],
            default => ['corn' => 500, 'materials' => ['wood' => 40, 'stone' => 30, $crop => 20]],
        };
    }

    /** @return list<string> The keys of a farm's patches, in the order they're cleared. */
    public static function fieldKeys(string $farm): array
    {
        return array_keys(array_filter(ValleyBuilder::FIELD_OF, fn (string $parent) => $parent === $farm));
    }

    /** The patch a farm grows into next, or null once it has grown all it can. */
    public static function nextField(Region $farm): ?Region
    {
        $keys = self::fieldKeys($farm->key);

        return Region::query()
            ->where('valley_id', $farm->valley_id)
            ->whereIn('key', $keys)
            ->where('wild', true)
            ->get()
            ->sortBy(fn (Region $field) => array_search($field->key, $keys, true))
            ->first();
    }

    /** How many of its patches a farm has grown into. */
    public static function grown(Region $farm): int
    {
        return Region::query()
            ->where('valley_id', $farm->valley_id)
            ->whereIn('key', self::fieldKeys($farm->key))
            ->where('wild', false)
            ->count();
    }

    /**
     * Grow a farm into its next patch of wild land: the patch becomes a new
     * region of the farm, held by the faction that grew it.
     *
     * @throws GameException
     */
    public function grow(Player $player, Region $farm): Region
    {
        return DB::transaction(function () use ($player, $farm) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if ($player->faction_id === null || ! $player->decides()) {
                throw new GameException('Only the leader and council of the faction holding a farm can grow it.');
            }

            $farm = Region::query()->lockForUpdate()->findOrFail($farm->id);

            if (self::fieldKeys($farm->key) === [] || $farm->valley_id !== $player->valley_id) {
                throw new GameException("{$farm->name} can't grow. Only the farms beyond the fences can.");
            }

            if ($farm->faction_id !== $player->faction_id) {
                throw new GameException("Your faction must hold {$farm->name} to grow it.");
            }

            $field = self::nextField($farm);

            if ($field === null) {
                throw new GameException("{$farm->name} has grown into all the land round it.");
            }

            $faction = Faction::query()->lockForUpdate()->findOrFail($player->faction_id);
            app(Bases::class)->pay($faction, self::growCost(self::grown($farm) + 1, (string) $farm->produces));

            $field->forceFill([
                'wild' => false,
                'faction_id' => $faction->id,
                'held_since' => CarbonImmutable::now(),
                'settled_until' => CarbonImmutable::now()->addHour(),
                // Newly cleared land starts with a day's water.
                'watered_until' => CarbonImmutable::now()->addDay(),
            ])->save();

            Notices::send(Notices::valley($farm->valley_id)->reject(fn (int $id) => $id === $player->id), 'farm', "🌱 {$faction->name} grew {$farm->name} into {$field->name}.", route('play', [], false));

            return $field;
        });
    }

    /**
     * @throws GameException
     */
    public function buy(Player $player, Region $farm): void
    {
        DB::transaction(function () use ($player, $farm) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if ($player->faction_id === null) {
                throw new GameException('Farms are bought by factions. Join or found one first.');
            }

            if (! $player->decides()) {
                throw new GameException("Only your faction's leader and council can buy a farm.");
            }

            $farm = Region::query()->lockForUpdate()->findOrFail($farm->id);

            if ($farm->wild) {
                throw new GameException("{$farm->name} is still wild. Whoever holds its farm can grow into it.");
            }

            if (! $farm->isFarm() || $farm->valley_id !== $player->valley_id) {
                throw new GameException("{$farm->name} isn't for sale. Only the farms beyond the fences are.");
            }

            if ($farm->faction_id === $player->faction_id) {
                throw new GameException("Your faction already holds {$farm->name}.");
            }

            if ($farm->faction_id !== null) {
                throw new GameException("{$farm->name} belongs to another faction. Take it in a raid instead.");
            }

            $faction = Faction::query()->lockForUpdate()->findOrFail($player->faction_id);

            if ($faction->treasury < self::PRICE) {
                throw new GameException("{$farm->name} costs ".self::PRICE." Corn from the treasury. Your faction has {$faction->treasury}.");
            }

            $faction->decrement('treasury', self::PRICE);
            $farm->forceFill(['faction_id' => $faction->id, 'held_since' => CarbonImmutable::now(), 'settled_until' => CarbonImmutable::now()->addHour()])->save();

            Notices::send(Notices::valley($farm->valley_id)->reject(fn (int $id) => $id === $player->id), 'farm', "🌾 {$faction->name} bought {$farm->name}.", route('play', [], false));
        });
    }

    /**
     * Water a farm you stand on: 10 water keeps it growing for 12 hours,
     * up to two days ahead. Anyone can, whoever holds it.
     *
     * @throws GameException
     */
    public function water(Player $player, Region $farm): Region
    {
        return DB::transaction(function () use ($player, $farm) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $farm = Region::query()->lockForUpdate()->findOrFail($farm->id);

            if (! $farm->isFarm() || $farm->wild || $farm->valley_id !== $player->valley_id) {
                throw new GameException('Only farmland needs watering.');
            }

            Movement::ensureStandingIn($player, $farm, 'water');
            $now = CarbonImmutable::now();
            $from = $farm->watered_until !== null && $farm->watered_until->isFuture() ? CarbonImmutable::instance($farm->watered_until) : $now;

            if ($from->gte($now->addHours(self::WATER_AHEAD_HOURS - self::WATER_HOURS + 1))) {
                throw new GameException("{$farm->name} has all the water it can hold. Come back tomorrow.");
            }

            $materials = $player->materials ?? [];

            if ((int) ($materials['water'] ?? 0) < self::WATER) {
                throw new GameException('Watering a farm takes '.self::WATER.' water. Fetch it from a pond, a mere or a spring.');
            }

            $materials['water'] -= self::WATER;
            $player->forceFill(['materials' => $materials])->save();
            $farm->forceFill(['watered_until' => $from->addHours(self::WATER_HOURS)])->save();

            return $farm;
        });
    }

    /**
     * The shortest way to water from a farm, as region keys from the water
     * to the farm, or null when no pond, mere or spring is near enough.
     *
     * @return list<string>|null
     */
    public static function trenchPath(Region $farm): ?array
    {
        $regions = Region::query()->where('valley_id', $farm->valley_id)->with('neighbours:id,key')->get(['id', 'key', 'ring', 'produces'])->keyBy('key');
        $from = [$farm->key => null];
        $edge = [$farm->key];

        for ($step = 0; $step < self::TRENCH_REACH; $step++) {
            $next = [];

            foreach ($edge as $key) {
                foreach ($regions[$key]->neighbours as $neighbour) {
                    $region = $regions[$neighbour->key] ?? null;

                    // Trenches don't run through the humans' town.
                    if ($region === null || array_key_exists($region->key, $from) || $region->isCity()) {
                        continue;
                    }

                    $from[$region->key] = $key;

                    if ($region->produces === 'water') {
                        $path = [$region->key];

                        while (($back = $from[end($path)]) !== null) {
                            $path[] = $back;
                        }

                        return $path;
                    }

                    $next[] = $region->key;
                }
            }

            $edge = $next;
        }

        return null;
    }

    /** @return array{corn: int, materials: array<string, int>} What a trench of so many stretches costs. */
    public static function trenchCost(int $stretches): array
    {
        return ['corn' => self::TRENCH_CORN * $stretches, 'materials' => ['wood' => self::TRENCH_WOOD * $stretches, 'stone' => self::TRENCH_STONE * $stretches]];
    }

    /**
     * Dig a trench from the nearest water to a farm your faction holds: the
     * farm, and every patch it has grown into, never runs dry again until a
     * rival fills it in.
     *
     * @throws GameException
     */
    public function dig(Player $player, Region $farm): Region
    {
        return DB::transaction(function () use ($player, $farm) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if ($player->faction_id === null || ! $player->decides()) {
                throw new GameException('Only the leader and council of the faction holding a farm can dig it a trench.');
            }

            $farm = Region::query()->lockForUpdate()->findOrFail($farm->id);

            if (! $farm->isFarm() || $farm->wild || $farm->valley_id !== $player->valley_id) {
                throw new GameException('Only farmland needs a trench.');
            }

            if ($farm->faction_id !== $player->faction_id) {
                throw new GameException("Your faction must hold {$farm->name} to dig it a trench.");
            }

            if ($farm->irrigated()) {
                throw new GameException("{$farm->name} already has water running to it.");
            }

            Movement::ensureStandingIn($player, $farm, 'dig');
            $path = self::trenchPath($farm);

            if ($path === null) {
                throw new GameException("There's no pond, mere or spring within ".self::TRENCH_REACH." regions of {$farm->name}.");
            }

            $faction = Faction::query()->lockForUpdate()->findOrFail($player->faction_id);
            app(Bases::class)->pay($faction, self::trenchCost(count($path) - 1));
            $farm->forceFill(['trench' => $path])->save();

            $source = Region::query()->where('valley_id', $farm->valley_id)->where('key', $path[0])->value('name');
            Notices::send(Notices::valley($farm->valley_id)->reject(fn (int $id) => $id === $player->id), 'farm', "💧 {$faction->name} dug a trench from {$source} to {$farm->name}.", route('play', [], false));

            return $farm;
        });
    }

    /**
     * Fill in a rival's trench where it runs through the region you stand
     * in. The farm keeps what water it has, then needs watering by hand.
     *
     * @throws GameException
     */
    public function fill(Player $player, Region $farm): Region
    {
        return DB::transaction(function () use ($player, $farm) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $farm = Region::query()->lockForUpdate()->findOrFail($farm->id);

            if ($farm->trench === null || $farm->valley_id !== $player->valley_id) {
                throw new GameException("{$farm->name} has no trench to fill in.");
            }

            if ($farm->faction_id !== null && $farm->faction_id === $player->faction_id) {
                throw new GameException("That's your own faction's trench.");
            }

            if (! in_array($player->location()->key, $farm->trench, true)) {
                throw new GameException("Walk to where the trench runs first: from its water to {$farm->name}.");
            }

            Horseshoes::spend($player, self::FILL);
            $farm->forceFill(['trench' => null])->save();

            Notices::send(Notices::members($farm->faction_id), 'farm', "🪨 {$player->name} filled in the trench to {$farm->name}. Water it by hand, or dig it again.", route('play', [], false));

            return $farm;
        });
    }

    /** Every held farm pays its faction's treasury. */
    public function payIncome(Season $season): void
    {
        Region::query()
            ->where('valley_id', $season->valley_id)
            ->where('ring', 'farm')
            ->whereNotNull('faction_id')
            ->get()
            // A parched farm pays nothing until it's watered.
            ->reject(fn (Region $farm) => $farm->isParched())
            // An Iron Plough doubles what a faction's farms pay.
            ->each(fn (Region $farm) => Stores::addCorn($farm->faction_id, (int) (self::INCOME * Powers::plough($farm->faction_id))));
    }
}
