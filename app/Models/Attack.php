<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A raid on a region. It marches for an hour (two on the Farmhouse), so the
 * defenders can answer, then settles at the tick.
 *
 * @property int $id
 * @property int $season_id
 * @property int $faction_id
 * @property int $region_id
 * @property int|null $defender_faction_id
 * @property int|null $player_id
 * @property Carbon $lands_at
 * @property Carbon|null $resolved_at
 * @property string|null $winner
 * @property string|null $attack_strength
 * @property string|null $defence_strength
 * @property int $seed
 * @property Faction $faction
 * @property Faction|null $defender
 * @property Region $region
 * @property Player|null $launcher
 */
#[Fillable(['season_id', 'faction_id', 'region_id', 'defender_faction_id', 'player_id', 'lands_at', 'seed'])]
class Attack extends Model
{
    protected function casts(): array
    {
        return [
            'lands_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return BelongsTo<Faction, $this> */
    public function defender(): BelongsTo
    {
        return $this->belongsTo(Faction::class, 'defender_faction_id');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function launcher(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /** @return HasMany<BattleCommitment, $this> */
    public function commitments(): HasMany
    {
        return $this->hasMany(BattleCommitment::class);
    }
}
