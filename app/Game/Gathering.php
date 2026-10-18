<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * Working the region you stand in for Corn or materials. One horseshoe a
 * trip. Land your own faction holds yields half as much again, and the
 * House takes its grain tax from every bushel of Corn.
 */
final class Gathering
{
    public const CORN = 20;

    public const MATERIAL = 5;

    /**
     * @return array{what: string, amount: int, tax: int}
     *
     * @throws GameException
     */
    public function gather(Player $player, Region $region): array
    {
        if ($region->produces === null) {
            throw new GameException("Nothing grows in {$region->name}. Try a field or the woods.");
        }

        return DB::transaction(function () use ($player, $region) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            Movement::ensureStandingIn($player, $region, 'gather');
            Horseshoes::spend($player, 1);

            $base = $region->produces === 'corn' ? self::CORN : self::MATERIAL;
            $own = $player->faction_id !== null && $region->faction_id === $player->faction_id;
            $workshop = $region->produces === 'corn' ? 1.0 : Bases::gathering($player, $region);
            $amount = (int) floor($base * $player->species->gathering() * ($own ? 1.5 : 1.0) * $workshop);
            $tax = 0;

            if ($region->produces === 'corn') {
                $season = $player->valley->currentSeason();
                $tax = $this->tax($season, $amount);
                $player->corn += $amount - $tax;
            } else {
                $materials = $player->materials;
                $materials[$region->produces] = (int) ($materials[$region->produces] ?? 0) + $amount;
                $player->materials = $materials;
            }

            $player->save();
            Missions::record($player, 'gather');

            return ['what' => $region->produces, 'amount' => $amount - $tax, 'tax' => $tax];
        });
    }

    private function tax(?Season $season, int $amount): int
    {
        if ($season === null) {
            return 0;
        }

        $law = Wall::law($season, LawTemplate::GrainTax);
        $house = $season->valley->house();

        if ($law === null || $house === null) {
            return 0;
        }

        $tax = (int) floor($amount * $law->value / 100);

        if ($tax > 0) {
            Faction::query()->whereKey($house->id)->increment('treasury', $tax);
            Season::query()->whereKey($season->id)->increment('tax_collected', $tax);
        }

        return $tax;
    }
}
