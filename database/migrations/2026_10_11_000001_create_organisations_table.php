<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('name');
            $table->string('forwarding_code', 16)->unique();
            // Negotiated prices. Null means the standard price from config.
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('success_fee_rate', 5, 4)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_staff')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organisation_id');
            $table->dropColumn('is_staff');
        });

        Schema::dropIfExists('organisations');
    }
};
