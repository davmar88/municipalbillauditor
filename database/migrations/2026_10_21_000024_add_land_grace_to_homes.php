<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A home on another faction's land has three days for its owner to join
 * that faction, or the faction moves it off: since when, and whether the
 * last day's warning went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homes', function (Blueprint $table) {
            $table->timestamp('foreign_since')->nullable();
            $table->boolean('foreign_warned')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('homes', fn (Blueprint $table) => $table->dropColumn(['foreign_since', 'foreign_warned']));
    }
};
