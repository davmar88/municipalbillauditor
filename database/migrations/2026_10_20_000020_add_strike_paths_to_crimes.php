<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Robberies and burnings are struck from anywhere on the map: where from,
 * where to, and when the criminal gets there, for the red arrows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crimes', function (Blueprint $table) {
            $table->float('from_x')->nullable();
            $table->float('from_y')->nullable();
            $table->float('to_x')->nullable();
            $table->float('to_y')->nullable();
            $table->dateTime('lands_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crimes', fn (Blueprint $table) => $table->dropColumn(['from_x', 'from_y', 'to_x', 'to_y', 'lands_at']));
    }
};
