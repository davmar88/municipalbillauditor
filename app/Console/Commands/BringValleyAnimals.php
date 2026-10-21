<?php

namespace App\Console\Commands;

use App\Game\ValleyAnimals;
use App\Models\Valley;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Bring animals the game runs itself into a valley, so it's never empty. */
#[Signature('game:valley-animals {count=20 : How many to bring in} {--valley= : The valley, by name; the first one if left out}')]
#[Description('Bring computer-run valley animals into a valley')]
class BringValleyAnimals extends Command
{
    public function handle(ValleyAnimals $animals): int
    {
        $valley = $this->option('valley')
            ? Valley::query()->where('name', $this->option('valley'))->first()
            : Valley::query()->orderBy('id')->first();

        if ($valley === null) {
            $this->error('No such valley.');

            return self::FAILURE;
        }

        $added = $animals->bring($valley, (int) $this->argument('count'));
        $this->info("{$added} valley animals came to {$valley->name}.");

        return self::SUCCESS;
    }
}
