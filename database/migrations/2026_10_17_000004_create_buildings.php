<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faction bases: one building per region, raised by the faction holding it,
 * and a store of materials members give their faction to build with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedTinyInteger('level')->default(1);
            $table->dateTime('ready_at');
            $table->timestamps();
        });

        Schema::table('factions', function (Blueprint $table) {
            $table->json('materials')->nullable()->after('treasury');
        });
    }

    public function down(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->dropColumn('materials');
        });

        Schema::dropIfExists('buildings');
    }
};
