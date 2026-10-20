<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When each animal was last seen, to show who is online. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->index(['valley_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropIndex(['valley_id', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
