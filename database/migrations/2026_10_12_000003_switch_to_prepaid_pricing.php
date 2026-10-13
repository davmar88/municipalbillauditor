<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Households now pay per job from prepaid credit, and agents and businesses
 * a flat monthly fee. The success fee and the paid managed dispute are gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('success_fee_rate');
        });

        Schema::table('organisations', function (Blueprint $table) {
            // A negotiated monthly fee. Null means the standard fee for the kind.
            $table->renameColumn('unit_price', 'monthly_fee');
        });

        Schema::table('organisations', function (Blueprint $table) {
            // Kept equal to the sum of the ledger, and only ever changed in
            // the same transaction as a ledger row, under a row lock.
            $table->decimal('credit_balance', 12, 2)->default(0)->after('monthly_fee');
        });

        // Every rand in or out of an organisation's prepaid credit.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            // Only set on job and refund rows: bill_check or dispute.
            $table->string('job', 20)->nullable();
            // Positive adds credit, negative spends it.
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            // A job included in an organisation's monthly fee costs nothing
            // but still counts towards the month's included checks.
            $table->boolean('covered_by_plan')->default(false);
            $table->nullableMorphs('subject');
            $table->string('description');
            // What makes this row happen once only: one charge per bill or
            // dispute, one credit per payment.
            $table->string('reference', 120)->unique();
            $table->timestamps();

            $table->index(['organisation_id', 'created_at']);
        });

        // Managed household disputes were invoiced at R299, and business
        // claims carried a success fee. Neither exists any more.
        DB::table('fee_invoices')->whereIn('kind', ['managed_dispute', 'back_refund', 'monthly_saving'])->delete();

        Schema::table('fee_invoices', function (Blueprint $table) {
            $table->dropColumn(['basis', 'rate']);
            $table->timestamp('paid_at')->nullable()->after('issued_on');
            // One monthly fee per organisation per month.
            $table->unique(['organisation_id', 'kind', 'period_month']);
        });

        DB::table('disputes')->where('mode', 'success_fee')->update(['mode' => 'managed']);

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn('back_refund_claimed');
        });

        Schema::table('bills', function (Blueprint $table) {
            // When a bill waiting for credit arrived, for the reminder.
            $table->timestamp('waiting_since')->nullable()->after('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('bills', fn (Blueprint $table) => $table->dropColumn('waiting_since'));

        Schema::table('disputes', function (Blueprint $table) {
            $table->decimal('back_refund_claimed', 12, 2)->nullable()->after('monthly_saving');
        });

        Schema::table('fee_invoices', function (Blueprint $table) {
            $table->dropUnique(['organisation_id', 'kind', 'period_month']);
            $table->dropColumn('paid_at');
            $table->decimal('basis', 12, 2)->nullable()->after('description');
            $table->decimal('rate', 6, 4)->nullable()->after('basis');
        });

        Schema::dropIfExists('credit_transactions');

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->renameColumn('monthly_fee', 'unit_price');
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->decimal('success_fee_rate', 5, 4)->nullable()->after('unit_price');
        });
    }
};
