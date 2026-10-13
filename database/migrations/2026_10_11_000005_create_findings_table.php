<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            // "check" is a reserved word in MySQL.
            $table->string('check_type', 30);
            $table->string('service', 20)->nullable();
            $table->string('confidence', 12);
            $table->string('state', 12)->default('open');
            $table->string('title');
            $table->text('explanation');
            $table->decimal('amount', 12, 2)->nullable();
            $table->boolean('amount_is_upper_bound')->default(false);
            $table->json('evidence');
            $table->string('calculation', 500)->nullable();
            $table->string('question')->nullable();
            $table->json('answer_options')->nullable();
            $table->string('answer', 30)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('action', 30)->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
