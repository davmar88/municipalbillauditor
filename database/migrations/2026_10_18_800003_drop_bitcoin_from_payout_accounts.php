<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash-outs are paid by bank transfer or PayPal only, so payout details no
 * longer hold a Bitcoin address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->dropColumn('bitcoin_address');
        });
    }

    public function down(): void
    {
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->string('bitcoin_address', 100)->nullable()->after('recipient_code');
        });
    }
};
