<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An apple tree an animal planted with Pineapples. It fruits after a few
 * days, then grows Apples into its basket until it has given them all.
 *
 * @property int $id
 * @property int $player_id
 * @property Carbon $fruits_at
 * @property int $total The Apples it gives in all
 * @property int $grown The Apples grown so far, harvested or not
 * @property int $basket Grown Apples waiting to be harvested
 * @property Carbon|null $grown_until How far its growing has been counted
 * @property Carbon|null $finished_at When its last Apple was harvested
 * @property Carbon $created_at
 * @property Player $player
 */
#[Fillable(['player_id', 'fruits_at', 'total', 'grown', 'basket', 'grown_until', 'finished_at'])]
class AppleTree extends Model
{
    protected function casts(): array
    {
        return [
            'fruits_at' => 'datetime',
            'grown_until' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
