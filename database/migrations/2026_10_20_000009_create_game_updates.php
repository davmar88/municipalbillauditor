<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What's new and what's fixed, for the public updates page; filled from Git on every deploy, and the owner can add to it on /admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_updates', function (Blueprint $table) {
            $table->id();
            $table->date('published_on');
            $table->string('kind', 8);
            $table->string('title', 120);
            $table->text('body');
            // The Git commit it came from, for lines filled in from Git on deploy.
            $table->string('commit', 40)->nullable()->unique();
            $table->timestamps();
            $table->index('published_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_updates');
    }
};
