<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reports of cheating, naming the player and the rule; and factions' own banners. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bug_reports', function (Blueprint $table) {
            $table->string('kind', 8)->default('bug');
            $table->foreignId('reported_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('rule', 8)->nullable();
        });

        Schema::table('factions', function (Blueprint $table) {
            $table->json('banner')->nullable();
            $table->string('banner_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bug_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_player_id');
            $table->dropColumn(['kind', 'rule']);
        });

        Schema::table('factions', function (Blueprint $table) {
            $table->dropColumn(['banner', 'banner_path']);
        });
    }
};
