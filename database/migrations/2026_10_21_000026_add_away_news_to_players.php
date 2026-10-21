<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * News by email of what happened while an animal was away: whether its
 * player wants it, and when the last one went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->boolean('news_emails')->default(true);
            $table->dateTime('news_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn(['news_emails', 'news_sent_at']));
    }
};
