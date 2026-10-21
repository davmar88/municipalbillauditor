<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who visits the site and what they do there, for the owner's Visitors page:
 * one row per page or action, with the country worked out from the internet
 * address. The address itself is never kept. And the country ranges that
 * lookup reads, from DB-IP's free country list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            // The same visitor on the same day, without keeping who they are.
            $table->char('visitor', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('country', 2)->nullable();
            $table->string('method', 8);
            $table->string('route', 80)->nullable();
            $table->string('path', 255);
            $table->string('referrer', 120)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ip_countries', function (Blueprint $table) {
            $table->binary('ip_from', 16);
            $table->binary('ip_to', 16);
            $table->char('country', 2);
            $table->index('ip_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_countries');
        Schema::dropIfExists('visits');
    }
};
