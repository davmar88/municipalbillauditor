<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling Apple packs: each purchase a player starts with Paystack or
 * PayPal, the settings the owner types in on the admin page (payment keys
 * are stored encrypted), and who may open that page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('is_staff');
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Kept when an account is deleted: they are the record of money taken.
        Schema::create('apple_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pack', 32);
            $table->unsignedInteger('apples');
            $table->string('provider', 16);
            $table->char('currency', 3);
            // In cents.
            $table->unsignedInteger('amount');
            $table->string('reference', 64)->unique();
            $table->string('provider_id', 64)->nullable()->index();
            $table->string('status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['player_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_purchases');
        Schema::dropIfExists('app_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
