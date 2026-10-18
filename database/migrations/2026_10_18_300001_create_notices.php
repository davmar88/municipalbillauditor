<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bell: game news for one animal, like a raid on its faction's land, a
 * sale in its shop or a request to join, until it's read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('text', 300);
            $table->string('url')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
            $table->index(['player_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
