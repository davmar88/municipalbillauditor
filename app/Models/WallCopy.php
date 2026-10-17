<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player's dated copy of the Wall, saved with "Remember the Wall". Only
 * the server writes it, so a copy is proof of what the Wall said then.
 *
 * @property int $id
 * @property int $player_id
 * @property int $season_id
 * @property array<int, array{slot: int, template: string, value: int|null, target: string|null, wording: string}> $laws
 * @property Carbon $created_at
 */
#[Fillable(['player_id', 'season_id', 'laws'])]
class WallCopy extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['laws' => 'array'];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
