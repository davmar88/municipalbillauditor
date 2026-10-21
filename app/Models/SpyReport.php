<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a Spyglass saw of a faction, kept for the spy's faction to read.
 *
 * @property int $id
 * @property int $player_id
 * @property int|null $faction_id
 * @property int $target_faction_id
 * @property array<string, mixed> $report
 */
#[Fillable(['player_id', 'faction_id', 'target_faction_id', 'report'])]
class SpyReport extends Model
{
    protected function casts(): array
    {
        return ['report' => 'array'];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
