<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The Market Levy is gone from the Barn Wall for good: paint it over wherever it stands. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('laws')->where('template', 'market_levy')->delete();
    }

    public function down(): void
    {
        // A law painted over stays painted over.
    }
};
