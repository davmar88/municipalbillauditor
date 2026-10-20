<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A home on fire can be put out before it burns down, and an outlaw who is
 * caught pays back what their crimes took.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crimes', function (Blueprint $table) {
            $table->foreignId('doused_by')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamp('repaid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crimes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('doused_by');
            $table->dropColumn('repaid_at');
        });
    }
};
