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
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('draft');
            // The findings disputed (kept as a record even if a re-audit
            // later removes a finding).
            $table->json('finding_ids');
            $table->bigInteger('amount_disputed_cents')->default(0);
            // Encrypted at rest: the letter contains the account number and
            // property address.
            $table->text('letter_subject');
            $table->text('letter_body');
            $table->string('channel', 16)->nullable();
            $table->string('municipality_reference')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('response_due_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->dateTime('escalated_at')->nullable();
            $table->bigInteger('outcome_amount_cents')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('note')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_events');
        Schema::dropIfExists('disputes');
    }
};
