<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An animal walking off to set up camp: the home it puts up where it
 * arrives, 0 for a Tent or 1 for a Hut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('camp_on_arrival');
        });
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedTinyInteger('camp_level')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('camp_level');
            $table->boolean('camp_on_arrival')->default(false);
        });
    }
};
