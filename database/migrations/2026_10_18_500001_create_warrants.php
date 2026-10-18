<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outlaws: the farm section a plunder hit, and the House's warrants with
 * the bounties bounty hunters collect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crimes', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('target_player_id')->constrained()->nullOnDelete();
        });

        Schema::create('warrants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('bounty');
            $table->string('status')->default('open');
            $table->foreignId('hunter_id')->nullable()->constrained('players')->nullOnDelete();
            $table->dateTime('escaped_until')->nullable();
            $table->dateTime('served_at')->nullable();
            $table->timestamps();
            $table->index(['season_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warrants');

        Schema::table('crimes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });
    }
};
