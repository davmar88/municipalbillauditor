<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horseshoes one player put into one side of a raid.
 *
 * @property int $id
 * @property int $attack_id
 * @property int $player_id
 * @property string $side
 * @property int $horseshoes
 * @property Player $player
 */
#[Fillable(['attack_id', 'player_id', 'side', 'horseshoes'])]
class BattleCommitment extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
