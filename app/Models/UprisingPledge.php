<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $uprising_id
 * @property int $player_id
 * @property int|null $faction_id
 * @property string $side rebel or loyal
 * @property int $horseshoes
 * @property Player $player
 */
#[Fillable(['uprising_id', 'player_id', 'faction_id', 'side', 'horseshoes'])]
class UprisingPledge extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
