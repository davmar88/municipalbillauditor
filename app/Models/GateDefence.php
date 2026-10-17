<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Horseshoes a player put into holding the gate on a Gate Night.
 *
 * @property int $id
 * @property int $season_id
 * @property int $player_id
 * @property Carbon $night_on
 * @property int $horseshoes
 * @property Player $player
 */
#[Fillable(['season_id', 'player_id', 'night_on', 'horseshoes'])]
class GateDefence extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['night_on' => 'date'];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
