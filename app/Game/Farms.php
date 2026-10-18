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

    /** Every held farm pays its faction's treasury. */
    public function payIncome(Season $season): void
    {
        Region::query()
            ->where('valley_id', $season->valley_id)
            ->where('ring', 'farm')
            ->whereNotNull('faction_id')
            ->get()
            // An Iron Plough doubles what a faction's farms pay.
            ->each(fn (Region $farm) => Faction::query()->whereKey($farm->faction_id)->increment('treasury', self::INCOME * Powers::plough($farm->faction_id)));
    }
}
