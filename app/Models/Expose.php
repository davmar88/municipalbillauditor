<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's claim that a law on the Wall was secretly changed.
 *
 * @property int $id
 * @property int $player_id
 * @property int|null $law_version_id
 * @property int $slot
 * @property bool $correct
 * @property int|null $rank
 */
#[Fillable(['player_id', 'law_version_id', 'slot', 'correct', 'rank'])]
class Expose extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['correct' => 'boolean'];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<LawVersion, $this> */
    public function lawVersion(): BelongsTo
    {
        return $this->belongsTo(LawVersion::class);
    }
}
