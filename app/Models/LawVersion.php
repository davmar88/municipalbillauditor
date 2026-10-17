<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every change ever made to the Wall. A secret edit stays out of the public
 * history until someone exposes it, or until 72 hours pass and it settles
 * in as if "the Wall has always said this".
 *
 * @property int $id
 * @property int $season_id
 * @property int $slot
 * @property string|null $template
 * @property int|null $value
 * @property string|null $target
 * @property string $wording
 * @property int|null $player_id
 * @property bool $secret
 * @property Carbon|null $exposed_at
 * @property Carbon|null $settled_at
 * @property Carbon $created_at
 */
#[Fillable(['season_id', 'slot', 'template', 'value', 'target', 'wording', 'player_id', 'secret', 'created_at'])]
class LawVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'secret' => 'boolean',
            'exposed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Secret, and still within the 72 hours someone can catch it. */
    public function isHidden(): bool
    {
        return $this->secret && $this->exposed_at === null && $this->settled_at === null;
    }
}
