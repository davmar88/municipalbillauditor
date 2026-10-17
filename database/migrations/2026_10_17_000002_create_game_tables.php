<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The game: valleys and their regions, the players' animals, factions, the
 * Barn Wall, battles, uprisings, crime, chat, the market and Rosettes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valleys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedTinyInteger('boots')->default(0);
            $table->unsignedTinyInteger('bonus_law_changes')->default(0);
            $table->dateTime('house_since')->nullable();
            $table->dateTime('last_secret_edit_at')->nullable();
            $table->dateTime('last_festival_at')->nullable();
            $table->unsignedInteger('tax_collected')->default(0);
            $table->date('last_rations_on')->nullable();
            $table->date('last_gate_night_on')->nullable();
            $table->timestamps();
            $table->unique(['valley_id', 'number']);
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('ring');
            $table->string('produces')->nullable();
            $table->unsignedTinyInteger('col');
            $table->unsignedTinyInteger('row');
            $table->unsignedBigInteger('faction_id')->nullable()->index();
            $table->dateTime('held_since')->nullable();
            $table->dateTime('settled_until')->nullable();
            $table->timestamps();
            $table->unique(['valley_id', 'key']);
        });

        Schema::create('region_links', function (Blueprint $table) {
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->foreignId('neighbour_id')->constrained('regions')->cascadeOnDelete();
            $table->primary(['region_id', 'neighbour_id']);
        });

        Schema::create('factions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('colour');
            $table->string('motto')->nullable();
            $table->unsignedInteger('treasury')->default(0);
            $table->string('entry_mode')->default('open');
            $table->unsignedTinyInteger('min_level')->default(1);
            $table->json('species')->nullable();
            $table->unsignedInteger('entry_fee')->default(0);
            $table->boolean('accepts_criminals')->default(true);
            $table->timestamps();
            $table->unique(['valley_id', 'name']);
        });

        Schema::table('regions', function (Blueprint $table) {
            $table->foreign('faction_id')->references('id')->on('factions')->nullOnDelete();
        });

        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('species');
            $table->string('language')->default('English');
            $table->unsignedInteger('rosettes')->default(0);
            $table->unsignedInteger('corn')->default(0);
            $table->json('materials');
            $table->unsignedTinyInteger('horseshoes')->default(10);
            $table->dateTime('horseshoes_at');
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('faction_role')->nullable();
            $table->dateTime('faction_joined_at')->nullable();
            $table->dateTime('shield_until')->nullable();
            $table->dateTime('home_closed_until')->nullable();
            $table->dateTime('home_burned_at')->nullable();
            $table->dateTime('pound_until')->nullable();
            $table->unsignedSmallInteger('wanted')->default(0);
            $table->date('last_visit_on')->nullable();
            $table->dateTime('adult_confirmed_at');
            $table->timestamps();
            $table->unique(['valley_id', 'name']);
        });

        Schema::create('faction_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['faction_id', 'player_id']);
        });

        Schema::create('laws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->string('template');
            $table->integer('value')->nullable();
            $table->string('target')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['season_id', 'slot']);
        });

        Schema::create('law_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->string('template')->nullable();
            $table->integer('value')->nullable();
            $table->string('target')->nullable();
            $table->string('wording');
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('secret')->default(false);
            $table->dateTime('exposed_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['season_id', 'slot']);
        });

        Schema::create('wall_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->json('laws');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('exposes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('law_version_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->boolean('correct');
            $table->unsignedTinyInteger('rank')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('unrest_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('amount');
            $table->string('reason');
            $table->dateTime('expires_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('attacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->foreignId('defender_faction_id')->nullable()->constrained('factions')->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('lands_at');
            $table->dateTime('resolved_at')->nullable();
            $table->string('winner')->nullable();
            $table->decimal('attack_strength', 10, 2)->nullable();
            $table->decimal('defence_strength', 10, 2)->nullable();
            $table->unsignedInteger('seed');
            $table->timestamps();
        });

        Schema::create('battle_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('side');
            $table->unsignedTinyInteger('horseshoes');
            $table->timestamp('created_at')->nullable();
            $table->unique(['attack_id', 'player_id']);
        });

        Schema::create('uprisings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->unsignedSmallInteger('pledges_needed');
            $table->dateTime('gathering_until');
            $table->dateTime('closes_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->decimal('rebel_strength', 10, 2)->nullable();
            $table->decimal('loyal_strength', 10, 2)->nullable();
            $table->foreignId('winner_faction_id')->nullable()->constrained('factions')->nullOnDelete();
            $table->unsignedInteger('seed');
            $table->timestamps();
        });

        Schema::create('uprising_pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uprising_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('side');
            $table->unsignedTinyInteger('horseshoes');
            $table->timestamp('created_at')->nullable();
            $table->unique(['uprising_id', 'player_id']);
        });

        Schema::create('gate_defences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->date('night_on');
            $table->unsignedTinyInteger('horseshoes');
            $table->timestamp('created_at')->nullable();
            $table->unique(['season_id', 'player_id', 'night_on']);
        });

        Schema::create('crimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->foreignId('target_faction_id')->nullable()->constrained('factions')->nullOnDelete();
            $table->foreignId('target_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->dateTime('settles_at');
            $table->dateTime('resolved_at')->nullable();
            $table->boolean('caught')->nullable();
            $table->unsignedInteger('loot')->default(0);
            $table->unsignedInteger('seed');
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            $table->string('channel')->index();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('chat_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
            $table->string('language');
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->unique(['chat_message_id', 'language']);
        });

        Schema::create('item_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('template');
            $table->string('name');
            $table->json('colours');
            $table->string('pattern');
            $table->unsignedInteger('edition')->nullable();
            $table->unsignedInteger('made')->default(0);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_design_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('serial');
            $table->timestamps();
            $table->unique(['item_design_id', 'serial']);
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price');
            $table->unsignedInteger('levy')->default(0);
            $table->foreignId('buyer_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->dateTime('sold_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rosette_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('amount');
            $table->string('reason');
            $table->string('reference')->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->dateTime('ran_at');
            $table->unique(['season_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach ([
            'ticks', 'rosette_entries', 'listings', 'items', 'item_designs', 'chat_translations',
            'chat_messages', 'crimes', 'gate_defences', 'uprising_pledges', 'uprisings',
            'battle_commitments', 'attacks', 'unrest_events', 'exposes', 'wall_copies',
            'law_versions', 'laws', 'faction_join_requests', 'players',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('regions', fn (Blueprint $table) => $table->dropForeign(['faction_id']));

        foreach (['factions', 'region_links', 'regions', 'seasons', 'valleys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
