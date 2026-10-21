<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The daily streak: days in a row an animal came back, and the last day counted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedSmallInteger('streak_days')->default(0);
            $table->date('streak_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn(['streak_days', 'streak_on']));
    }
};
