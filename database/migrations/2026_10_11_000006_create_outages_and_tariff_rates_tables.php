<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metro_id')->constrained()->cascadeOnDelete();
            $table->string('suburb')->index();
            $table->string('service', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::create('tariff_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metro_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('service', 20);
            $table->decimal('rate', 10, 2);
            $table->string('unit', 10);
            $table->date('effective_from')->nullable();
            $table->string('source')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->index(['metro_id', 'category', 'service']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_rates');
        Schema::dropIfExists('outages');
    }
};
