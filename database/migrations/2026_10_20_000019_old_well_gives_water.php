<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Old Well gives water now, not clay (her call, 2026-10-20). Clay had
 * nowhere else to come from, and buildings need it, so Silt Pools on the
 * frontier give clay instead of water.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('regions')->where('key', 'old_well')->update(['produces' => 'water']);
        DB::table('regions')->where('key', 'silt_pools')->update(['produces' => 'clay']);
    }

    public function down(): void
    {
        DB::table('regions')->where('key', 'old_well')->update(['produces' => 'clay']);
        DB::table('regions')->where('key', 'silt_pools')->update(['produces' => 'water']);
    }
};
