<?php

namespace App\Console\Commands;

use App\Services\Municipalities\MunicipalityImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Loads or refreshes the directory of every municipality. Safe to run again:
 * rows are matched on their code, and staff-verified emails are kept.
 */
#[Signature('municipalities:import {path? : A CSV in the database/data/municipalities.csv format}')]
#[Description('Load every South African municipality from the directory file')]
class ImportMunicipalities extends Command
{
    public function handle(MunicipalityImporter $importer): int
    {
        $result = $importer->import($this->argument('path'));

        $this->info("{$result['created']} added, {$result['updated']} updated.");

        return self::SUCCESS;
    }
}
