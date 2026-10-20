<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A band of Irongate's plunderers riding for a faction's farm.
 *
 * @property int $id
 * @property int $season_id
 * @property int $region_id
 * @property int|null $faction_id Who held the farm when the band rode out
 * @property int $men
 * @property Carbon $lands_at
 * @property Carbon|null $resolved_at
 * @property bool|null $won Whether the animals drove the band off
 * @property float|null $strength The animals' strength on the day
 * @property int $haul Corn the band carried off, or the animals took from it
 * @property int $seed
 * @property Region $region
 * @property Faction|null $faction
 */
#[Fillable(['season_id', 'region_id', 'faction_id', 'men', 'lands_at', 'seed'])]
class PlunderBand extends Model
{
    protected function casts(): array
    {
        return [
            'lands_at' => 'datetime',
            'resolved_at' => 'datetime',
            'won' => 'boolean',
            'strength' => 'float',
        ];
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return HasMany<PlunderFighter, $this> */
    public function fighters(): HasMany
    {
        return $this->hasMany(PlunderFighter::class);
    }
}
