<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Every animal now holds up to 500 horseshoes a day: room for it, and everyone filled up to 500. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedSmallInteger('horseshoes')->default(10)->change();
        });

        DB::table('players')->where('horseshoes', '<', 500)->update(['horseshoes' => 500, 'horseshoes_at' => now()]);
    }

    public function down(): void
    {
        // Horseshoes handed out stay handed out.
    }
};
