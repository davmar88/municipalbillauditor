<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members who lent a hand building a level of their faction's building:
 * each one makes the work go faster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_helps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->timestamp('created_at')->nullable();

            $table->unique(['building_id', 'player_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_helps');
    }
};
