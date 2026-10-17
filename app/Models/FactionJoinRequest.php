<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lone wolf asking to join a faction that approves its members.
 *
 * @property int $id
 * @property int $faction_id
 * @property int $player_id
 * @property string $status
 * @property Faction $faction
 * @property Player $player
 */
#[Fillable(['faction_id', 'player_id', 'status'])]
class FactionJoinRequest extends Model
{
    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
