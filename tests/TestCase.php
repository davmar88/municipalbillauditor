<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The tests were written round a ten-horseshoe purse; the game itself gives 50. The sums work the same either way.
        config(['game.horseshoes.max' => 10, 'game.horseshoes.saddlebag' => 40]);
        // Hunger and thirst would eat into the Corn other tests count: SurvivalTest turns them on.
        config(['game.survival.enabled' => false]);
        // Most market tests sell as lone animals: MarketTest turns the faction rule on to check it.
        config(['game.market.faction_only' => false]);
        // And price in Corn as well as Apples: MarketTest turns Apples-only on to check it.
        config(['game.market.apples_only' => false]);
        // And make recipes outside a faction: MarketTest turns the recipe rule on to check it.
        config(['game.market.recipes_faction_only' => false]);
        // The daily streak would add Corn to every first visit: StreakTest turns it on.
        config(['game.streak.enabled' => false]);
        // The crime tests were written with waits between crimes: they still check them.
        config(['game.crime.cooldowns' => true]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
