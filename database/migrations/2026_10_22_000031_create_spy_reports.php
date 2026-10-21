<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a Spyglass saw of a faction: its store, its strength and its weapons, for the spy's faction. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spy_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_faction_id')->constrained('factions')->cascadeOnDelete();
            $table->json('report');
            $table->timestamps();
            $table->index(['target_faction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spy_reports');
    }
};
