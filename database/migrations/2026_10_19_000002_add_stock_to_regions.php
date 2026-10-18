<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is left to gather in each region, and since when it has been growing
 * back. Empty means full: nobody has gathered there yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->unsignedInteger('stock')->nullable()->after('produces');
            $table->timestamp('stock_at')->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn(['stock', 'stock_at']);
        });
    }
};
