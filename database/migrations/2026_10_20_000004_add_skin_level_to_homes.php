<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skins from the skin shop: each level dresses a home up and makes it
 * harder to burn down or to arrest anyone hiding inside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homes', function (Blueprint $table) {
            $table->unsignedTinyInteger('skin_level')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('homes', function (Blueprint $table) {
            $table->dropColumn('skin_level');
        });
    }
};
