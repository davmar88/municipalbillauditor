<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the bill itself prints about the municipality that sent it.
        // The query email here is the second choice for sending a dispute,
        // after an address our staff have verified.
        Schema::table('bills', function (Blueprint $table) {
            $table->string('municipality_name')->nullable()->after('account_number');
            $table->string('municipality_email')->nullable()->after('municipality_name');
            $table->string('municipality_address')->nullable()->after('municipality_email');
            $table->string('municipality_phone', 60)->nullable()->after('municipality_address');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['municipality_name', 'municipality_email', 'municipality_address', 'municipality_phone']);
        });
    }
};
