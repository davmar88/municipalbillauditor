<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apple trees an animal planted with Pineapples: how many Apples each will
 * give in all, how many it has grown, and how many wait in its basket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apple_trees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->timestamp('fruits_at');
            $table->unsignedInteger('total');
            $table->unsignedInteger('grown')->default(0);
            $table->unsignedInteger('basket')->default(0);
            $table->timestamp('grown_until')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_trees');
    }
};
