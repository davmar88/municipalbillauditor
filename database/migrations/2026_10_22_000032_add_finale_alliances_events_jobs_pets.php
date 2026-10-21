<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Six things to keep the valley lively (her ask, 2026-10-22): the Hall of
 * Fame for season and weekly winners, alliances between factions, valley
 * events anyone can race to, the job board the valley animals post on, and
 * pets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hall_of_fame', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            // season_faction, season_animal or week.
            $table->string('kind', 20);
            $table->unsignedInteger('season_number');
            $table->unsignedTinyInteger('place')->default(1);
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->string('colour', 20)->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->date('week_of')->nullable();
            $table->timestamps();
            $table->index(['valley_id', 'kind']);
        });

        Schema::create('alliances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ally_id')->constrained('factions')->cascadeOnDelete();
            // proposed, active, declined or broken.
            $table->string('status', 12)->default('proposed');
            $table->foreignId('broken_by')->nullable()->constrained('factions')->nullOnDelete();
            $table->dateTime('broken_at')->nullable();
            $table->timestamps();
            $table->index(['faction_id', 'status']);
            $table->index(['ally_id', 'status']);
        });

        Schema::create('valley_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('places')->default(5);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
            $table->index(['valley_id', 'ends_at']);
        });

        Schema::create('valley_event_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['valley_event_id', 'player_id']);
        });

        Schema::create('valley_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            // rob, raid, defend or bring.
            $table->string('kind', 12);
            $table->string('poster', 40);
            $table->foreignId('target_faction_id')->nullable()->constrained('factions')->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('material', 20)->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->unsignedInteger('reward_corn')->default(0);
            $table->unsignedInteger('reward_rosettes')->default(0);
            $table->foreignId('done_by')->nullable()->constrained('players')->nullOnDelete();
            $table->dateTime('done_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['valley_id', 'done_at', 'expires_at']);
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->unique()->constrained()->cascadeOnDelete();
            // dog or horse.
            $table->string('kind', 12);
            $table->string('name', 30);
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedInteger('feeds')->default(0);
            $table->dateTime('fed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pets');
        Schema::dropIfExists('valley_jobs');
        Schema::dropIfExists('valley_event_entries');
        Schema::dropIfExists('valley_events');
        Schema::dropIfExists('alliances');
        Schema::dropIfExists('hall_of_fame');
    }
};
