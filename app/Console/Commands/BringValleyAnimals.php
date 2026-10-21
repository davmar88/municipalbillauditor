<?php

namespace App\Console\Commands;

use App\Game\ValleyAnimals;
use App\Models\Faction;
use App\Models\Valley;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Bring animals the game runs itself into a valley, so it's never empty. */
#[Signature('game:valley-animals {count=20 : How many to bring in} {--valley= : The valley, by name; the first one if left out} {--join= : A faction they join instead, by name} {--found=0 : How many factions of their own they found} {--members=5 : Members in each faction they found}')]
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

        if ($this->option('join')) {
            $faction = Faction::query()->where('valley_id', $valley->id)->where('name', $this->option('join'))->first();

            if ($faction === null) {
                $this->error('No such faction.');

                return self::FAILURE;
            }

            $joined = $animals->joinFaction($faction, (int) $this->argument('count'));
            $this->info("{$joined} valley animals joined {$faction->name}.");

            return self::SUCCESS;
        }

        if ((int) $this->option('found') > 0) {
            $made = $animals->foundFactions($valley, (int) $this->option('found'), (int) $this->option('members'));
            $this->info(count($made).' factions of valley animals: '.collect($made)->pluck('name')->implode(', ').'.');

            return self::SUCCESS;
        }

        $added = $animals->bring($valley, (int) $this->argument('count'));
        $this->info("{$added} valley animals came to {$valley->name}.");

        return self::SUCCESS;
    }
}
