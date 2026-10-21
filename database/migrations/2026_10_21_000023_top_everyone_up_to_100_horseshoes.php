<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every animal now holds up to 100 horseshoes: fill everyone with fewer up to 100. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('players')->where('horseshoes', '<', 100)->update(['horseshoes' => 100, 'horseshoes_at' => now()]);
    }

    public function down(): void
    {
        // Horseshoes handed out stay handed out.
    }
};
