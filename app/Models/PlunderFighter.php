<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horseshoes an animal put into fighting a band of plunderers.
 *
 * @property int $id
 * @property int $plunder_band_id
 * @property int $player_id
 * @property int|null $faction_id
 * @property int $horseshoes
 * @property float $boost From a weapon: a Sling or an Iron Sword
 * @property Player $player
 */
#[Fillable(['plunder_band_id', 'player_id', 'faction_id', 'horseshoes', 'boost'])]
class PlunderFighter extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['boost' => 'float'];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
