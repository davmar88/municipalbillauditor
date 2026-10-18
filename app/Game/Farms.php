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
            ->each(fn (Region $farm) => Faction::query()->whereKey($farm->faction_id)->increment('treasury', self::INCOME));
    }
}
