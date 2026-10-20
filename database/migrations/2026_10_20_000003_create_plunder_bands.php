<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plunderers ride out of Irongate for a faction's farm, and factions fight
 * them off together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plunder_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('men');
            $table->timestamp('lands_at');
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('won')->nullable();
            $table->decimal('strength', 8, 2)->nullable();
            $table->unsignedInteger('haul')->default(0);
            $table->unsignedBigInteger('seed');
            $table->timestamps();
            $table->index(['season_id', 'resolved_at']);
        });

        Schema::create('plunder_fighters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plunder_band_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('horseshoes');
            $table->timestamp('created_at')->nullable();
            $table->unique(['plunder_band_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plunder_fighters');
        Schema::dropIfExists('plunder_bands');
    }
};
