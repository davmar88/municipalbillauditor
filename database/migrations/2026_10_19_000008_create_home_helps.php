<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faction mates who lent a hand raising a level of an animal's home: each
 * one makes the work go faster, once a level. Homes now grow past level 255.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_helps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->timestamp('created_at')->nullable();

            $table->unique(['home_id', 'player_id', 'level']);
        });

        Schema::table('homes', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_helps');
    }
};
