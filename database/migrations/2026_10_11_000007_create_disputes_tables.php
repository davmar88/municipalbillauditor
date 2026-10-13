<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 12);
            $table->string('letter_subject');
            $table->text('letter_body');
            $table->decimal('disputed_total', 12, 2);
            $table->decimal('undisputed_amount', 12, 2)->nullable();
            $table->date('dispute_deadline')->nullable();
            // When the customer approved the letter. For managed disputes our
            // team lodges it after this; self-sent ones are lodged at once.
            $table->date('submitted_on')->nullable();
            $table->date('lodged_on')->nullable();
            $table->string('reference', 60)->nullable();
            $table->date('acknowledged_on')->nullable();
            $table->date('reply_received_on')->nullable();
            $table->date('escalated_councillor_on')->nullable();
            $table->date('escalated_public_protector_on')->nullable();
            $table->date('tariff_fixed_on')->nullable();
            $table->decimal('monthly_saving', 12, 2)->nullable();
            $table->decimal('back_refund_claimed', 12, 2)->nullable();
            $table->date('credit_on')->nullable();
            $table->decimal('credit_amount', 12, 2)->nullable();
            $table->foreignId('credit_bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->date('closed_on')->nullable();
            $table->string('close_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('dispute_finding', function (Blueprint $table) {
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->primary(['dispute_id', 'finding_id']);
        });

        Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->date('happened_on');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('description');
            $table->decimal('basis', 12, 2)->nullable();
            $table->decimal('rate', 6, 4)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 12)->default('due');
            $table->date('period_month')->nullable();
            $table->date('issued_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_invoices');
        Schema::dropIfExists('dispute_events');
        Schema::dropIfExists('dispute_finding');
        Schema::dropIfExists('disputes');
    }
};
