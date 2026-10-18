<?php

use App\Game\Geography;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walking anywhere on the map: where each animal stands as a point, and the
 * walk it is on, from where to where and when it arrives. Animals already
 * in the valley stand where their region's lanes meet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->double('pos_x')->nullable()->after('region_id');
            $table->double('pos_y')->nullable()->after('pos_x');
            $table->double('walk_from_x')->nullable()->after('pos_y');
            $table->double('walk_from_y')->nullable()->after('walk_from_x');
            $table->double('walk_to_x')->nullable()->after('walk_from_y');
            $table->double('walk_to_y')->nullable()->after('walk_to_x');
            $table->timestamp('walk_started_at')->nullable()->after('walk_to_y');
            $table->timestamp('walk_arrives_at')->nullable()->after('walk_started_at');
        });

        DB::table('players')
            ->join('regions', 'regions.id', '=', 'players.region_id')
            ->select('players.id', 'regions.key')
            ->get()
            ->each(function (object $player) {
                [$x, $y] = Geography::hub($player->key);
                DB::table('players')->where('id', $player->id)->update(['pos_x' => $x, 'pos_y' => $y]);
            });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_y', 'walk_from_x', 'walk_from_y', 'walk_to_x', 'walk_to_y', 'walk_started_at', 'walk_arrives_at']);
        });
    }
};
