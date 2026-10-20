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
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
