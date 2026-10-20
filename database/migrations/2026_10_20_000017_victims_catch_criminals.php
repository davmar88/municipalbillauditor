<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crimes no longer land anyone in the pound by chance: the victim catches
 * the criminal, or puts a bounty on them from their own Apples.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crimes', function (Blueprint $table) {
            $table->foreignId('caught_by')->nullable()->constrained('players')->nullOnDelete();
            $table->dateTime('escaped_until')->nullable();
        });

        Schema::table('warrants', function (Blueprint $table) {
            // A victim's own warrant, paid from their own Apples, rather than a faction's.
            $table->foreignId('issuer_id')->nullable()->constrained('players')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warrants', fn (Blueprint $table) => $table->dropConstrainedForeignId('issuer_id'));
        Schema::table('crimes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caught_by');
            $table->dropColumn('escaped_until');
        });
    }
};
