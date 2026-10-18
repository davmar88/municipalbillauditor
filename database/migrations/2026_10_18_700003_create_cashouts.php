<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashing Apples out: where each animal wants its money sent and who they
 * are (encrypted, and gone with the account), and every cash-out from the
 * request through the owner's approval to the payout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->unique()->constrained()->cascadeOnDelete();
            // The encrypted columns are text: encryption makes them long.
            $table->text('legal_name');
            $table->string('document_type', 16);
            $table->text('document_number');
            $table->char('country', 2);
            $table->string('method', 16);
            $table->text('paypal_email')->nullable();
            $table->string('bank_code', 16)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->text('account_number')->nullable();
            $table->string('recipient_code', 64)->nullable();
            $table->string('bitcoin_address', 100)->nullable();
            // Set when Paystack confirmed the bank account belongs to this ID.
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // Kept when an account is deleted: they are the record of money paid.
        Schema::create('cashouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 64)->unique();
            $table->unsignedInteger('apples');
            $table->string('method', 16);
            $table->char('currency', 3);
            // In cents.
            $table->unsignedInteger('amount');
            // Where the money goes and who to, as it was when asked for.
            $table->text('destination');
            $table->string('status', 16)->default('pending');
            $table->string('provider_id', 100)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashouts');
        Schema::dropIfExists('payout_accounts');
    }
};
