<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Card payments through Paystack: a top-up, or a monthly fee.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 20);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ZAR');
            // Ours, sent to Paystack and echoed back in its webhook.
            $table->string('reference', 60)->unique();
            $table->string('status', 20)->default('pending');
            // A monthly fee charged to the saved card, rather than at checkout.
            $table->boolean('saved_card')->default(false);
            $table->string('gateway_message')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // The card an agent or business pays its monthly fee with. Paystack
        // keeps the card; we keep its reusable authorization code, encrypted.
        Schema::table('organisations', function (Blueprint $table) {
            $table->text('card_authorization')->nullable()->after('credit_balance');
            $table->string('card_last4', 4)->nullable()->after('card_authorization');
            $table->string('card_brand', 30)->nullable()->after('card_last4');
            $table->string('card_expiry', 7)->nullable()->after('card_brand');
            $table->string('card_email')->nullable()->after('card_expiry');
            $table->timestamp('card_saved_at')->nullable()->after('card_email');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn(['card_authorization', 'card_last4', 'card_brand', 'card_expiry', 'card_email', 'card_saved_at']);
        });

        Schema::dropIfExists('payments');
    }
};
