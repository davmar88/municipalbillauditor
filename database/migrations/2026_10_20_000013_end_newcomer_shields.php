<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Newcomers are no longer protected from crime: lift the shields already given. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('players')->whereNotNull('shield_until')->update(['shield_until' => null]);
    }

    public function down(): void
    {
        // Shields lifted stay lifted.
    }
};
