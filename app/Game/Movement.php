<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Region;
use Illuminate\Support\Facades\DB;

/**
 * Walking the map. An animal stands in one region and walks to a
 * neighbouring one, one region at a time. Walking is free: gathering and
 * raids are what cost horseshoes.
 */
final class Movement
{
    /**
     * @throws GameException
     */
    public function walk(Player $player, Region $target): void
    {
        if ($target->valley_id !== $player->valley_id) {
            throw new GameException('That region is in another valley.');
        }

        DB::transaction(function () use ($player, $target) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $here = $player->location();

            if ($here->is($target)) {
                throw new GameException("You're already in {$target->name}.");
            }

            if (! $here->neighbours()->whereKey($target->id)->exists()) {
                throw new GameException("{$target->name} isn't next to {$here->name}. Walk one region at a time.");
            }

            $player->forceFill(['region_id' => $target->id])->save();
            Missions::record($player, 'walk');
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
