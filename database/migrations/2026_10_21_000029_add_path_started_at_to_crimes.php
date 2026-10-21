<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a robber's walk to its victim last set off: a Speedo on the way restarts it, faster. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crimes', function (Blueprint $table) {
            $table->dateTime('path_started_at')->nullable()->after('lands_at');
        });
    }

    public function down(): void
    {
        Schema::table('crimes', fn (Blueprint $table) => $table->dropColumn('path_started_at'));
    }
};
