<?php

namespace App\Console\Commands;

use App\Game\ValleyBuilder;
use App\Models\Valley;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lays out Hollowmere and starts its first season, and brings every valley
 * opened since up to date with new regions. Safe to run again: it only adds
 * what is missing.
 */
#[Signature('game:setup {name=Hollowmere : The valley to lay out}')]
#[Description('Lay out a valley, its regions and its first season')]
class GameSetup extends Command
{
    public function handle(ValleyBuilder $builder): int
    {
        $names = Valley::query()->pluck('name')->push($this->argument('name'))->unique();

        foreach ($names as $name) {
            $valley = $builder->build($name);
            $season = $valley->currentSeason();

            $this->info("{$valley->name} is ready: {$valley->regions()->count()} regions, season {$season?->number} until {$season?->ends_at->toDayDateTimeString()}.");
        }

        return self::SUCCESS;
    }
}
