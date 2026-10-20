<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Animals and factions can change their names, once a week. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->timestamp('renamed_at')->nullable();
        });
        Schema::table('factions', function (Blueprint $table) {
            $table->timestamp('renamed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('renamed_at'));
        Schema::table('factions', fn (Blueprint $table) => $table->dropColumn('renamed_at'));
    }
};
