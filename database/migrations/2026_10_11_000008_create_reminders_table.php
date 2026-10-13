<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per reminder sent, so the daily run never sends the same
        // one twice.
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->morphs('subject');
            $table->string('kind', 40);
            $table->date('due_on');
            $table->timestamp('sent_at');
            $table->unique(['subject_type', 'subject_id', 'kind', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
