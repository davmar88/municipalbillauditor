<?php

namespace App\Models;

use App\Game\Bases;
use App\Game\ValleyBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property int|null $stock What is left to gather; null when full
 * @property Carbon|null $stock_at Since when it has been growing back
 * @property int $col
 * @property int $row
 * @property bool $wild Wild land round a farm, not yet grown into
 * @property int|null $faction_id
 * @property Carbon|null $held_since
 * @property Carbon|null $settled_until
 * @property list<string>|null $trench The regions its irrigation trench runs through, from the water to the farm.
 * @property Carbon|null $watered_until Until when a farm is watered; a parched farm grows nothing.
 * @property Faction|null $faction
 * @property Building|null $building
 */
#[Fillable(['valley_id', 'key', 'name', 'ring', 'produces', 'col', 'row'])]
class Region extends Model
{
    protected function casts(): array
    {
        return [
            'held_since' => 'datetime',
            'watered_until' => 'datetime',
            'trench' => 'array',
            'settled_until' => 'datetime',
            'stock_at' => 'datetime',
            'wild' => 'boolean',
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

    /** @return HasOne<Building, $this> */
    public function building(): HasOne
    {
        return $this->hasOne(Building::class);
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

    /** A farm that has run out of water: it can't be gathered and pays nothing. */
    public function isParched(): bool
    {
        return $this->isFarm() && ! $this->wild && ! $this->irrigated() && ($this->watered_until === null || $this->watered_until->isPast());
    }

    /** Fed by a trench: its own, or the farm's it was grown from. */
    public function irrigated(): bool
    {
        if ($this->trench !== null) {
            return true;
        }

        $farm = ValleyBuilder::FIELD_OF[$this->key] ?? null;

        if ($farm !== null && self::query()->where('valley_id', $this->valley_id)->where('key', $farm)->whereNotNull('trench')->exists()) {
            return true;
        }

        // A faction's Well keeps its farm watered too.
        return $this->isFarm() && Bases::wellWaters($this);
    }

    /** One of the farms beyond Hollowmere's fences. */
    public function isFarm(): bool
    {
        return $this->ring === 'farm';
    }

    /** The open scrub between the regions: anyone's to walk, gather and camp on, and nobody's to hold. */
    public function isScrub(): bool
    {
        return $this->ring === 'scrub';
    }

    /** Ironfield, the humans' mining country: nobody holds it, and missions there get harder the deeper you go. */
    public function isIronfield(): bool
    {
        return $this->ring === 'ironfield';
    }

    /** One of Irongate's districts: the humans' town, which nobody holds. */
    public function isCity(): bool
    {
        return $this->ring === 'city';
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
