<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Powers from used items: what is in effect, for an animal, a faction or a
 * region, by charges or until a time. A used item stays on record but
 * leaves its owner's things; battles remember a fighter's Iron Shoes and
 * crimes a Night Cloak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->unsignedInteger('charges')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'expires_at']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dateTime('used_at')->nullable()->after('serial');
        });

        Schema::table('battle_commitments', function (Blueprint $table) {
            $table->decimal('boost', 4, 2)->default(1)->after('horseshoes');
        });

        Schema::table('crimes', function (Blueprint $table) {
            $table->boolean('cloaked')->default(false)->after('seed');
        });
    }

    public function down(): void
    {
        Schema::table('crimes', function (Blueprint $table) {
            $table->dropColumn('cloaked');
        });

        Schema::table('battle_commitments', function (Blueprint $table) {
            $table->dropColumn('boost');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('used_at');
        });

        Schema::dropIfExists('effects');
    }
};
