<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faction studies: the level reached in each, and the one under way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->json('studies')->nullable()->after('materials');
            $table->string('studying', 20)->nullable()->after('studies');
            $table->timestamp('studying_until')->nullable()->after('studying');
        });
    }

    public function down(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->dropColumn(['studies', 'studying', 'studying_until']);
        });
    }
};
