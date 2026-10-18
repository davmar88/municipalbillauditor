<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apples, the game's premium currency: a balance for every animal and a
 * ledger of every Apple earned or spent. Earned Apples stay in the game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedInteger('apples')->default(0)->after('corn');
        });

        Schema::create('apple_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason');
            $table->string('reference')->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_entries');

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('apples');
        });
    }
};
