<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shops can price an item in Apples as well as Corn, and the game keeps a
 * share of every Apple sale. Orchard membership, bought with Apples, runs
 * until a date and gives one free horseshoe refill a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('currency', 8)->default('corn')->after('price');
            // The Apples the game kept from an Apple sale.
            $table->unsignedInteger('cut')->default(0)->after('levy');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->timestamp('orchard_until')->nullable()->after('apples');
            // The valley day the member last took their free refill.
            $table->date('orchard_refill_on')->nullable()->after('orchard_until');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['orchard_until', 'orchard_refill_on']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['currency', 'cut']);
        });
    }
};
