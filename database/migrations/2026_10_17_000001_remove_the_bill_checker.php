<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * RevenueGuard stopped checking municipal bills and became a game. The bill
 * checker's tables go; users and sign-in stay.
 */
return new class extends Migration
{
    private const TABLES = [
        'dispute_finding', 'dispute_events', 'dispute_messages', 'reminders', 'disputes',
        'charges', 'meter_readings', 'findings', 'bills', 'properties', 'outages',
        'tariff_rates', 'claude_usages', 'credit_transactions', 'payments', 'fee_invoices',
        'organisations', 'municipalities', 'metros',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('users', 'organisation_id')) {
            Schema::table('users', function ($table) {
                $table->dropConstrainedForeignId('organisation_id');
            });
        }

        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // The bill checker lives on in git history; it isn't restored here.
    }
};
