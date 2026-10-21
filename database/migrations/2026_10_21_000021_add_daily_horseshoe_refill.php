<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every 24 hours an animal's horseshoes are topped back up to the usual
 * most: when that last happened. Everyone's clock starts now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dateTime('horseshoes_filled_at')->nullable()->after('horseshoes_at');
        });
        DB::table('players')->update(['horseshoes_filled_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('horseshoes_filled_at'));
    }
};
