<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Region;
use Illuminate\Support\Facades\DB;

/**
 * Walking the map. An animal stands in one region and walks to a
 * neighbouring one for a horseshoe. Walking inside your own faction's land
 * is free, so holding land joined up pays.
 */
final class Movement
{
    /**
     * @return int What the walk cost in horseshoes.
     *
     * @throws GameException
     */
    public function walk(Player $player, Region $target): int
    {
        if ($target->valley_id !== $player->valley_id) {
            throw new GameException('That region is in another valley.');
        }

        return DB::transaction(function () use ($player, $target) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $here = $player->location();

            if ($here->is($target)) {
                throw new GameException("You're already in {$target->name}.");
            }

            if (! $here->neighbours()->whereKey($target->id)->exists()) {
                throw new GameException("{$target->name} isn't next to {$here->name}. Walk one region at a time.");
            }

            $ownLand = $player->faction_id !== null
                && $here->faction_id === $player->faction_id
                && $target->faction_id === $player->faction_id;
            $cost = $ownLand ? 0 : 1;

            if ($cost > 0) {
                Horseshoes::spend($player, $cost);
            }

            $player->forceFill(['region_id' => $target->id])->save();

            return $cost;
        });
    }

    /**
     * @throws GameException
     */
    public static function ensureStandingIn(Player $player, Region $region, string $doing): void
    {
        if ($player->location()->id !== $region->id) {
            throw new GameException("Walk to {$region->name} first: you {$doing} where you stand.");
        }
    }
}
