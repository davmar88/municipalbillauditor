<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What every Claude call cost, against the organisation and the bill
        // or dispute it was for, so staff can see the margin.
        Schema::create('claude_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('purpose', 30);
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            // Null when the model has no price in config yet.
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->decimal('usd_zar_rate', 8, 4);
            $table->decimal('cost_zar', 12, 4)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_usages');
    }
};
