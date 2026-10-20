<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner can close a valley to newcomers before it's full; newcomers
 * then start in the next valley with room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valleys', function (Blueprint $table) {
            $table->boolean('closed_to_newcomers')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('valleys', function (Blueprint $table) {
            $table->dropColumn('closed_to_newcomers');
        });
    }
};
