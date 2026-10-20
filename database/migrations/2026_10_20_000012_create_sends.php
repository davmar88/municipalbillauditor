<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Corn, Apples, Pineapples and materials one animal sent another: for the daily limits, and for checking for cheats. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('to_player_id')->constrained('players')->cascadeOnDelete();
            $table->string('what', 20);
            $table->unsignedInteger('amount');
            $table->timestamp('created_at')->nullable();
            $table->index(['from_player_id', 'what', 'created_at']);
            $table->index(['to_player_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sends');
    }
};
