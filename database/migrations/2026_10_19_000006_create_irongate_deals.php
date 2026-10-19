<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deals struck with Irongate's people: materials sold to the fence and tips
 * bought from the informer, so a day's sales and a hunter's tips can be
 * counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irongate_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('material', 20)->nullable();
            $table->unsignedInteger('amount')->default(0);
            $table->unsignedInteger('corn')->default(0);
            $table->foreignId('warrant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['player_id', 'kind', 'created_at']);
        });

        // When an animal last left or was thrown out of a faction.
        Schema::table('players', function (Blueprint $table) {
            $table->timestamp('left_faction_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('left_faction_at');
        });
        Schema::dropIfExists('irongate_deals');
    }
};
