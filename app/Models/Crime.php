<?php

namespace App\Models;

use App\Enums\CrimeKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lone wolf's crime. It settles at the next tick: either it works, or the
 * guards catch the criminal.
 *
 * @property int $id
 * @property int $season_id
 * @property int $player_id
 * @property CrimeKind $kind
 * @property int|null $target_faction_id
 * @property int|null $target_player_id
 * @property int|null $region_id The farm section a plunder hit
 * @property Region|null $region
 * @property Carbon $settles_at
 * @property Carbon|null $resolved_at
 * @property bool|null $caught
 * @property int $loot
 * @property int $seed
 * @property Player $player
 * @property Faction|null $targetFaction
 * @property Player|null $targetPlayer
 * @property int|null $doused_by Who put the fire out, for a burn
 * @property Carbon|null $repaid_at When the outlaw paid back what it took
 * @property bool $armed A robber with a cudgel
 */
#[Fillable(['season_id', 'player_id', 'kind', 'target_faction_id', 'target_player_id', 'region_id', 'settles_at', 'seed', 'cloaked'])]
class Crime extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => CrimeKind::class,
            'settles_at' => 'datetime',
            'resolved_at' => 'datetime',
            'caught' => 'boolean',
            'cloaked' => 'boolean',
            'repaid_at' => 'datetime',
            'armed' => 'boolean',
            'escaped_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Faction, $this> */
    public function targetFaction(): BelongsTo
    {
        return $this->belongsTo(Faction::class, 'target_faction_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function targetPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'target_player_id');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
