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
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('bill_date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_date')->nullable();
            // Current charges for the period in cents (excludes arrears).
            $table->bigInteger('total_cents')->nullable();
            $table->string('status', 32);
            $table->string('extraction_source', 16)->default('manual');
            // Random file name on the private "bills" disk.
            $table->string('file_path')->nullable();
            $table->string('file_mime')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'bill_date']);
        });

        Schema::create('line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('service', 32);
            $table->string('description')->nullable();
            $table->string('tariff_category')->nullable();
            $table->string('reading_type', 16)->default('unknown');
            $table->decimal('previous_reading', 14, 3)->nullable();
            $table->decimal('current_reading', 14, 3)->nullable();
            $table->decimal('consumption', 14, 3)->nullable();
            $table->string('unit', 8)->nullable();
            $table->bigInteger('amount_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_items');
        Schema::dropIfExists('bills');
    }
};
