<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Weapons in fights: a fighter's boost against plunderers, and a robber's cudgel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plunder_fighters', function (Blueprint $table) {
            $table->decimal('boost', 4, 2)->default(1);
        });

        Schema::table('crimes', function (Blueprint $table) {
            $table->boolean('armed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('plunder_fighters', function (Blueprint $table) {
            $table->dropColumn('boost');
        });

        Schema::table('crimes', function (Blueprint $table) {
            $table->dropColumn('armed');
        });
    }
};
