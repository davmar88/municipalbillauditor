<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private messages between any two animals: one conversation per pair,
 * blocks, reports our team reads, and a mute our team can hand out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // The pair, lower player id first, so a pair has one conversation.
            $table->foreignId('player_one_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('player_two_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('started_by_id')->constrained('players')->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['player_one_id', 'player_two_id']);
        });

        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('players')->cascadeOnDelete();
            $table->text('body');
            // The reader's own language, kept so it is translated once.
            $table->text('translation')->nullable();
            $table->string('translation_language')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['recipient_id', 'read_at']);
        });

        Schema::create('player_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('players')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['player_id', 'blocked_id']);
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('direct_message_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('players')->cascadeOnDelete();
            $table->string('reason');
            $table->string('note', 300)->nullable();
            $table->string('outcome')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('players', function (Blueprint $table) {
            $table->timestamp('muted_until')->nullable()->after('wanted');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('muted_until');
        });

        Schema::dropIfExists('message_reports');
        Schema::dropIfExists('player_blocks');
        Schema::dropIfExists('direct_messages');
        Schema::dropIfExists('conversations');
    }
};
