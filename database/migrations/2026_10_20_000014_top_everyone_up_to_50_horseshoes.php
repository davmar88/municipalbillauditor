<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every animal now holds up to 50 horseshoes: fill everyone with fewer up to 50. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('players')->where('horseshoes', '<', 50)->update(['horseshoes' => 50, 'horseshoes_at' => now()]);
    }

    public function down(): void
    {
        // Horseshoes handed out stay handed out.
    }
};
