<?php

namespace Database\Seeders;

use App\Services\Municipalities\MunicipalityImporter;
use Illuminate\Database\Seeder;

/**
 * Every municipality in South Africa, from database/data/municipalities.csv.
 */
class MunicipalitySeeder extends Seeder
{
    public function run(MunicipalityImporter $importer): void
    {
        $importer->import();
    }
}
