<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items one animal gave another: who to whom, and when, so a day's gifts
 * can be counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('to_player_id')->constrained('players')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['from_player_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};
