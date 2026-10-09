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
            $table->foreignId('line_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule', 32);
            // Service the finding is about (null for bill-level rules).
            $table->string('service', 32)->nullable();
            // rule + service: used to keep a finding's status across re-audits.
            $table->string('match_key', 80);
            $table->string('severity', 8);
            $table->decimal('confidence', 4, 3);
            $table->string('title');
            $table->text('explanation');
            $table->bigInteger('estimated_overcharge_cents')->nullable();
            $table->json('evidence');
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->unique(['bill_id', 'match_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
