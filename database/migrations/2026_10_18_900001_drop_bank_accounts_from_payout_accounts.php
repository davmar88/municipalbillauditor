<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash-outs are paid to PayPal only, so payout details no longer hold a bank
 * account, and the Paystack key is no longer kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Details for a way the game no longer pays can't be paid to: the
        // animal saves its PayPal email instead.
        DB::table('payout_accounts')->where('method', '!=', 'paypal')->delete();
        DB::table('app_settings')->where('key', 'paystack_secret_key')->delete();

        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'bank_name', 'account_number', 'recipient_code', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->string('bank_code', 16)->nullable()->after('paypal_email');
            $table->string('bank_name', 100)->nullable()->after('bank_code');
            $table->text('account_number')->nullable()->after('bank_name');
            $table->string('recipient_code', 64)->nullable()->after('account_number');
            $table->timestamp('verified_at')->nullable()->after('recipient_code');
        });
    }
};
