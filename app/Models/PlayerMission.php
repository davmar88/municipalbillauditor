<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player's progress on one mission: a step of the story, or a daily job
 * for one day.
 *
 * @property int $id
 * @property int $player_id
 * @property string $key
 * @property string $period
 * @property int $progress
 * @property int $target
 * @property Carbon|null $completed_at
 * @property Carbon|null $claimed_at
 * @property Player $player
 */
#[Fillable(['player_id', 'key', 'period', 'progress', 'target', 'completed_at', 'claimed_at'])]
class PlayerMission extends Model
{
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function isStory(): bool
    {
        return $this->period === 'story';
    }
}
