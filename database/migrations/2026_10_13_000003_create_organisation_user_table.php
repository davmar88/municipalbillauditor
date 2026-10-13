<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person can hold several accounts (a home, a rental portfolio, a
     * business) and switch between them. users.organisation_id stays: it is
     * the account they are working in now.
     */
    public function up(): void
    {
        Schema::create('organisation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
        });

        // Everyone belongs to the account they have now.
        DB::table('organisation_user')->insertUsing(
            ['organisation_id', 'user_id', 'created_at', 'updated_at'],
            DB::table('users')->whereNotNull('organisation_id')->select('organisation_id', 'id', 'created_at', 'updated_at'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_user');
    }
};
