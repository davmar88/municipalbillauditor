<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A power in effect from a used item: on an animal, a faction or a region,
 * lasting a number of uses (charges) or until a time.
 *
 * @property int $id
 * @property int|null $player_id
 * @property int|null $faction_id
 * @property int|null $region_id
 * @property string $kind
 * @property int|null $charges
 * @property Carbon|null $expires_at
 */
#[Fillable(['player_id', 'faction_id', 'region_id', 'kind', 'charges', 'expires_at'])]
class Effect extends Model
{
    protected function casts(): array
    {
        return [
            'charges' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Effects still working: uses left, or time left.
     *
     * @param  Builder<Effect>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $active) => $active
            ->where(fn (Builder $timed) => $timed->whereNotNull('expires_at')->where('expires_at', '>', now()))
            ->orWhere(fn (Builder $counted) => $counted->whereNull('expires_at')->where('charges', '>', 0)));
    }
}
