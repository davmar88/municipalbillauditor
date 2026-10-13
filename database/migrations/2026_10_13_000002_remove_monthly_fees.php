<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every account pays as it goes, from prepaid credit. Monthly fees, the
     * invoices raised for them and the cards saved to pay them are gone.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_invoice_id');
            $table->dropColumn('saved_card');
        });

        Schema::dropIfExists('fee_invoices');

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_fee',
                'card_authorization', 'card_last4', 'card_brand', 'card_expiry', 'card_email', 'card_saved_at',
            ]);
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropColumn('covered_by_plan');
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->boolean('covered_by_plan')->default(false)->after('balance_after');
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->decimal('monthly_fee', 10, 2)->nullable()->after('forwarding_code');
            $table->text('card_authorization')->nullable()->after('credit_balance');
            $table->string('card_last4', 4)->nullable()->after('card_authorization');
            $table->string('card_brand', 30)->nullable()->after('card_last4');
            $table->string('card_expiry', 7)->nullable()->after('card_brand');
            $table->string('card_email')->nullable()->after('card_expiry');
            $table->timestamp('card_saved_at')->nullable()->after('card_email');
        });

        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->string('status', 12)->default('due');
            $table->date('period_month')->nullable();
            $table->date('issued_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'kind', 'period_month']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('fee_invoice_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->boolean('saved_card')->default(false)->after('status');
        });
    }
};
