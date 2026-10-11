<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10);
            $table->string('status', 20)->default('received');
            $table->boolean('needs_review')->default(false);
            $table->string('review_note')->nullable();

            // The original file, on the private disk.
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();

            // What the bill says.
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('tariff_label')->nullable();
            $table->string('tariff_category', 20)->default('unknown');
            $table->decimal('total_due', 12, 2)->nullable();

            $table->string('read_method', 10)->nullable();
            $table->json('extraction')->nullable();
            $table->json('passed')->nullable();
            $table->json('skipped')->nullable();
            $table->string('meter_photo_path')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'bill_date']);
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('service', 20);
            $table->string('meter_number', 40)->nullable();
            $table->decimal('previous', 14, 3)->nullable();
            $table->decimal('current', 14, 3)->nullable();
            $table->decimal('consumption', 14, 3)->nullable();
            $table->string('reading_type', 12)->default('unknown');
            $table->string('unit', 10)->nullable();
        });

        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('service', 20);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('bills');
    }
};
