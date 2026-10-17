<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A patch of the valley's map. Factions hold regions; the one holding the
 * Farmhouse rules.
 *
 * @property int $id
 * @property int $valley_id
 * @property string $key
 * @property string $name
 * @property string $ring
 * @property string|null $produces
 * @property int $col
 * @property int $row
 * @property int|null $faction_id
 * @property Carbon|null $held_since
 * @property Carbon|null $settled_until
 * @property Faction|null $faction
 */
#[Fillable(['valley_id', 'key', 'name', 'ring', 'produces', 'col', 'row'])]
class Region extends Model
{
    protected function casts(): array
    {
        return [
            'held_since' => 'datetime',
            'settled_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Valley, $this> */
    public function valley(): BelongsTo
    {
        return $this->belongsTo(Valley::class);
    }

    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return BelongsToMany<Region, $this> */
    public function neighbours(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'region_links', 'region_id', 'neighbour_id');
    }

    public function isSafe(): bool
    {
        return $this->ring === 'safe';
    }

    public function isFarmhouse(): bool
    {
        return $this->key === 'farmhouse';
    }

    /** How much harder it is to take: the Farmhouse most, its neighbours next. */
    public function ground(): float
    {
        return match ($this->ring) {
            'seat' => 1.3,
            'inner' => 1.15,
            default => 1.0,
        };
    }
}
