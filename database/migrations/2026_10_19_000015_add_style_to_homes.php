<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The look an animal picks for its home on the 3D map: a village house, or
 * one of the farm buildings. Its level still decides how strong it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homes', function (Blueprint $table) {
            $table->string('style', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('homes', function (Blueprint $table) {
            $table->dropColumn('style');
        });
    }
};
