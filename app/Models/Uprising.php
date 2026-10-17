<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The animals rising against the House. It gathers pledges for two hours,
 * then rebels and loyalists put in horseshoes for six.
 *
 * @property int $id
 * @property int $season_id
 * @property int|null $player_id
 * @property string $status gathering, open, won, lost or failed
 * @property int $pledges_needed
 * @property Carbon $gathering_until
 * @property Carbon|null $closes_at
 * @property Carbon|null $resolved_at
 * @property string|null $rebel_strength
 * @property string|null $loyal_strength
 * @property int|null $winner_faction_id
 * @property int $seed
 */
#[Fillable(['season_id', 'player_id', 'status', 'pledges_needed', 'gathering_until', 'seed'])]
class Uprising extends Model
{
    protected function casts(): array
    {
        return [
            'gathering_until' => 'datetime',
            'closes_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return HasMany<UprisingPledge, $this> */
    public function pledges(): HasMany
    {
        return $this->hasMany(UprisingPledge::class);
    }

    /** @return BelongsTo<Faction, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Faction::class, 'winner_faction_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['gathering', 'open'], true);
    }
}
