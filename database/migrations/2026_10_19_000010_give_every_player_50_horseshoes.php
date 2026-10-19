<?php

use App\Game\Horseshoes;
use App\Models\Player;
use Illuminate\Database\Migrations\Migration;

/**
 * A one-off gift: 50 extra horseshoes for every player, on top of what
 * they have and kept until spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Player::query()->chunkById(200, function ($players) {
            foreach ($players as $player) {
                Horseshoes::bonus($player, 50);
            }
        });
    }

    public function down(): void
    {
        //
    }
};
