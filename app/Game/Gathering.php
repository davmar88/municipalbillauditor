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

    public const RARE_MATERIALS = ['honey', 'herbs', 'iron', 'gold', 'copper', 'pears', 'carrots', 'berries', 'pumpkins'];

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

            if ($region->isParched()) {
                throw new GameException("{$region->name} is parched. Water it first: 10 water from a pond, a mere or a spring.");
            }

            // A farm's rare crop is for anyone while the farm is unclaimed, and its holders' once it isn't.
            if ($region->isFarm() && $region->faction_id !== null && $region->faction_id !== $player->faction_id) {
                $holders = $region->faction?->name ?? 'another faction';

                throw new GameException("{$region->name}'s {$region->produces} belongs to {$holders}. Take the farm to gather it.");
            }

            // Each region holds only so much at a time, and grows it back.
            $region = Region::query()->lockForUpdate()->findOrFail($region->id);
            // The scrub lies everywhere, so it never runs out: it's just thin pickings.
            $left = $region->isScrub() ? PHP_INT_MAX : Stock::state($region)['left'];

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
            // A Workshop brings in more materials, a Mill more Corn.
            $workshop = $region->produces === 'corn' ? Bases::milling($player, $region) : Bases::gathering($player, $region);
            // The further from the farms, the less an animal brings in.
            [$x, $y] = Movement::position($player);
            $rich = Geography::richness($x, $y);
            // Gathering Gloves, a Feast Basket and the faction's Farming bring in more.
            // Summer brings plenty, winter little, and fall dries the water up.
            $weather = Weather::gathering($player->valley->currentSeason(), $region->produces);
            $amount = min($left, max(1, (int) floor($base * $rich * $player->species->gathering() * ($own ? 1.5 : 1.0) * $workshop * Powers::gathering($player, $region->produces) * Research::gathering($player) * Pets::gathering($player) * $weather)));

            // Water needs something to carry it in.
            if ($region->produces === 'water' && config('game.survival.enabled')) {
                $room = Survival::waterCap($player) - $player->material('water');

                if ($room < 1) {
                    throw new GameException('Your '.Survival::BARRELS[(int) $player->water_barrel]['name']." is full. Drink, give water to your faction's store, or buy a bigger one.");
                }

                $amount = min($amount, $room);
            }
            if (! $region->isScrub()) {
                Stock::take($region, $amount);
            }
            $tax = 0;

            if ($region->produces === 'corn') {
                $season = $player->valley->currentSeason();
                $tax = $this->tax($season, $amount);
                $bonus = $this->bonus($season, $amount);
                $player->corn += $amount - $tax + $bonus;
                $amount += $bonus;
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

    /** The Harvest Bonus: the House adds a share from its treasury, while it can pay. */
    private function bonus(?Season $season, int $amount): int
    {
        $law = $season !== null ? Wall::law($season, LawTemplate::HarvestBonus) : null;
        $house = $season?->valley->house();

        if ($law === null || $house === null) {
            return 0;
        }

        $bonus = (int) floor($amount * $law->value / 100);

        return $bonus > 0 && Faction::query()->whereKey($house->id)->where('treasury', '>=', $bonus)->decrement('treasury', $bonus) ? $bonus : 0;
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
            Stores::addCorn($house->id, $tax);
            Season::query()->whereKey($season->id)->increment('tax_collected', $tax);
        }

        return $tax;
    }
}
