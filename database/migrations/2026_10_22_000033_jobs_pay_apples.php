<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The bounty board pays Apples instead of Corn (her call, 2026-10-22), the open jobs included. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valley_jobs', function (Blueprint $table) {
            $table->unsignedInteger('reward_apples')->default(0)->after('reward_corn');
        });

        foreach (['rob' => 3, 'raid' => 5, 'defend' => 3] as $kind => $apples) {
            DB::table('valley_jobs')->whereNull('done_at')->where('kind', $kind)->update(['reward_apples' => $apples, 'reward_corn' => 0]);
        }

        DB::table('valley_jobs')->whereNull('done_at')->where('kind', 'bring')->update(['reward_apples' => DB::raw('GREATEST(1, FLOOR(amount / 10))'), 'reward_corn' => 0]);
    }

    public function down(): void
    {
        Schema::table('valley_jobs', fn (Blueprint $table) => $table->dropColumn('reward_apples'));
    }
};
