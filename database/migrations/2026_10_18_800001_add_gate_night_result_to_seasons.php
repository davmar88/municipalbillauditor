<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How the last Gate Night went, so the map can show it for the rest of the
 * evening. Raids that already happened get what can still be told: whether
 * the gate held and how many defended it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->json('last_gate_night')->nullable()->after('last_gate_night_on');
        });

        DB::table('seasons')->whereNotNull('last_gate_night_on')->get()->each(function (object $season) {
            $night = substr((string) $season->last_gate_night_on, 0, 10);

            DB::table('seasons')->where('id', $season->id)->update(['last_gate_night' => json_encode([
                'held' => DB::table('rosette_entries')->where('reference', 'like', "gate:{$night}:%")->where('reason', 'Held the gate')->exists(),
                'defenders' => DB::table('gate_defences')->where('season_id', $season->id)->where('night_on', $night)->count(),
                'burned' => [],
            ])]);
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('last_gate_night');
        });
    }
};
