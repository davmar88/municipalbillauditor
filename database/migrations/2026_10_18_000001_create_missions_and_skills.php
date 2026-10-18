<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growing a player: story and daily missions, and the two skills players
 * are ranked on, Market and Creativity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            // "story" for the story chain, or the day (Y-m-d) a daily job is for.
            $table->string('period', 10);
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('target');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['player_id', 'key', 'period']);
        });

        Schema::create('skill_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->string('skill', 20);
            $table->integer('amount');
            $table->string('reason');
            $table->string('reference')->unique();
            $table->timestamp('created_at')->nullable();
            $table->index(['skill', 'season_id']);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->unsignedInteger('market_skill')->default(0)->after('rosettes');
            $table->unsignedInteger('creativity_skill')->default(0)->after('market_skill');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['market_skill', 'creativity_skill']);
        });

        Schema::dropIfExists('skill_entries');
        Schema::dropIfExists('player_missions');
    }
};
