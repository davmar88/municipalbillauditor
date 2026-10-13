<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // While the municipality waits on something from the customer, we
        // remind the customer rather than escalate.
        Schema::table('disputes', function (Blueprint $table) {
            $table->date('info_requested_on')->nullable()->after('acknowledged_on');
            $table->date('info_reminded_on')->nullable()->after('info_requested_on');
            $table->date('info_answered_on')->nullable()->after('info_reminded_on');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', fn (Blueprint $table) => $table->dropColumn(['info_requested_on', 'info_reminded_on', 'info_answered_on']));
    }
};
