<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Any animal can raid any region; factions set their own raid rules, flag
 * members who break them, and can excuse a member or run rogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->json('raid_rules')->nullable();
            $table->boolean('rogue')->default(false);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->boolean('rules_exempt')->default(false);
        });

        Schema::create('faction_strikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('rule', 20);
            $table->string('detail', 120);
            $table->timestamp('created_at')->nullable();

            $table->index(['faction_id', 'player_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faction_strikes');

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('rules_exempt');
        });

        Schema::table('factions', function (Blueprint $table) {
            $table->dropColumn(['raid_rules', 'rogue']);
        });
    }
};
