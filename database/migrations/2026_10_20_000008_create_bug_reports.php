<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bugs players report, with a photo or video, for the owner to read and fix. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->text('description');
            $table->string('page', 255)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->string('device', 255)->nullable();
            $table->string('status', 12)->default('open');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
