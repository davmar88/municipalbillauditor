<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A faction's store of Apples, which members give and the House spends on
 * the bounties of its warrants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->unsignedInteger('apples')->default(0)->after('treasury');
        });
    }

    public function down(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->dropColumn('apples');
        });
    }
};
