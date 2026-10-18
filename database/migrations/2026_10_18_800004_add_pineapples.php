<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pineapples: what real money buys, swapped for packs of Apples. A balance
 * for every animal, a ledger of every Pineapple bought or swapped, and the
 * packs being bought now count Pineapples instead of Apples.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedInteger('pineapples')->default(0)->after('apples');
        });

        Schema::create('pineapple_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason');
            $table->string('reference')->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('apple_purchases', function (Blueprint $table) {
            $table->renameColumn('apples', 'pineapples');
        });
    }

    public function down(): void
    {
        Schema::table('apple_purchases', function (Blueprint $table) {
            $table->renameColumn('pineapples', 'apples');
        });

        Schema::dropIfExists('pineapple_entries');

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('pineapples');
        });
    }
};
