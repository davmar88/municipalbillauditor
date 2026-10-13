<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disputes are emailed to the municipality by us, and its replies come back
 * to an address of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            // The customer's one-time permission for us to send disputes
            // about their account on their behalf. Nothing is sent without it.
            $table->timestamp('dispute_mandate_at')->nullable()->after('card_saved_at');
            $table->foreignId('dispute_mandate_user_id')->nullable()->after('dispute_mandate_at')->constrained('users')->nullOnDelete();
            // Send a dispute as soon as a bill shows only likely errors.
            $table->boolean('auto_send_disputes')->default(false)->after('dispute_mandate_user_id');
        });

        Schema::table('disputes', function (Blueprint $table) {
            // Replies go to d-<token>@<inbound domain>.
            $table->string('reply_token', 40)->nullable()->unique()->after('close_reason');
            $table->string('sent_to')->nullable()->after('reply_token');
            $table->string('sent_to_source', 20)->nullable()->after('sent_to');
            // Approved but not sent, and why: no_address or no_mandate.
            $table->string('held_reason', 20)->nullable()->after('sent_to_source');
            $table->timestamp('held_since')->nullable()->after('held_reason');
            $table->date('followed_up_on')->nullable()->after('held_since');
            $table->date('escalated_manager_on')->nullable()->after('followed_up_on');
            $table->date('public_protector_draft_on')->nullable()->after('escalated_manager_on');
        });

        // Every email sent about a dispute, and every reply that came back.
        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);
            // Outgoing: dispute, follow_up or escalation. Incoming: reply.
            $table->string('kind', 20);
            $table->string('from_address')->nullable();
            $table->text('to_address')->nullable();
            $table->text('cc_address')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            // How Claude read a reply, and what it found.
            $table->string('classification', 30)->nullable();
            $table->json('reading')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropUnique(['reply_token']);
            $table->dropColumn([
                'reply_token', 'sent_to', 'sent_to_source', 'held_reason', 'held_since',
                'followed_up_on', 'escalated_manager_on', 'public_protector_draft_on',
            ]);
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispute_mandate_user_id');
            $table->dropColumn(['dispute_mandate_at', 'auto_send_disputes']);
        });
    }
};
