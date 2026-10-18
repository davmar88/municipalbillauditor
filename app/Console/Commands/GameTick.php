<?php

namespace App\Console\Commands;

use App\Game\Tick;
use App\Models\Valley;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('game:tick')]
#[Description('Move every valley on: battles, crimes, uprisings, upkeep, rations, Gate Night and season ends, whatever is due')]
class GameTick extends Command
{
    public function handle(Tick $tick): int
    {
        Valley::query()->each(fn (Valley $valley) => $tick->run($valley));

        return self::SUCCESS;
    }
}
