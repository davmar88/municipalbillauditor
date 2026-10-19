<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Farms need water: until when each is watered. Every farm starts with a
 * day's water, so none dries up the moment this arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->timestamp('watered_until')->nullable();
        });

        DB::table('regions')->where('ring', 'farm')->update(['watered_until' => now()->addDay()]);
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('watered_until');
        });
    }
};
