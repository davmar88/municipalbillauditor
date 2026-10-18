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
 * House takes its grain tax from every bushel of Corn. A region holds only
 * so much at a time and grows it back: see Stock.
 */
final class Gathering
{
    public const CORN = 20;

    public const MATERIAL = 5;

    /** Honey, herbs and iron from the farms beyond the fences come slower. */
    public const RARE = 3;

    public const RARE_MATERIALS = ['honey', 'herbs', 'iron'];

    /**
     * @return array{what: string, amount: int, tax: int}
     *
     * @throws GameException
     */
    public function gather(Player $player, Region $region): array
    {
        if ($region->produces === null || $region->wild) {
            throw new GameException("Nothing grows in {$region->name}. Try a field or the woods.");
        }

        return DB::transaction(function () use ($player, $region) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            Movement::ensureStandingIn($player, $region, 'gather');

            // A farm's rare crop is for anyone while the farm is unclaimed, and its holders' once it isn't.
            if ($region->isFarm() && $region->faction_id !== null && $region->faction_id !== $player->faction_id) {
                $holders = $region->faction?->name ?? 'another faction';

                throw new GameException("{$region->name}'s {$region->produces} belongs to {$holders}. Take the farm to gather it.");
            }

            // Each region holds only so much at a time, and grows it back.
            $region = Region::query()->lockForUpdate()->findOrFail($region->id);
            $left = Stock::state($region)['left'];

            if ($left < 1) {
                throw new GameException("{$region->name} is picked clean. More grows back by ".Clock::local(Stock::nextAt($region))->format('H:i').'.');
            }

            Horseshoes::spend($player, 1);

            $base = match (true) {
                $region->produces === 'corn' => self::CORN,
                in_array($region->produces, self::RARE_MATERIALS, true) => self::RARE,
                default => self::MATERIAL,
            };
            $own = $player->faction_id !== null && $region->faction_id === $player->faction_id;
            $workshop = $region->produces === 'corn' ? 1.0 : Bases::gathering($player, $region);
            // Gathering Gloves, a Feast Basket and the faction's Farming bring in more.
            $amount = min($left, (int) floor($base * $player->species->gathering() * ($own ? 1.5 : 1.0) * $workshop * Powers::gathering($player, $region->produces) * Research::gathering($player)));
            Stock::take($region, $amount);
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
