<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Municipalities often give their answer in an attached letter. The
        // files live on the private bill disk: [{path, name, mime, size}].
        Schema::table('dispute_messages', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('dispute_messages', fn (Blueprint $table) => $table->dropColumn('attachments'));
    }
};
