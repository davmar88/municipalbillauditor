<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pell the trader's goods have no maker among the animals, so a design's
 * maker can be empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_designs', function (Blueprint $table) {
            $table->foreignId('player_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('item_designs', function (Blueprint $table) {
            $table->foreignId('player_id')->nullable(false)->change();
        });
    }
};
