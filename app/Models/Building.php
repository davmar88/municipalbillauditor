<?php

namespace App\Models;

use App\Enums\BuildingKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A faction's building on a region it holds. While a level is going up the
 * building works at the level below, and it falls with the land under it.
 *
 * @property int $id
 * @property int $faction_id
 * @property int $region_id
 * @property BuildingKind $kind
 * @property int $level
 * @property Carbon $ready_at
 * @property Carbon|null $crowned_at When a Head Office made its faction the ruler
 * @property Faction $faction
 * @property Region $region
 */
#[Fillable(['faction_id', 'region_id', 'kind', 'level', 'ready_at', 'crowned_at'])]
class Building extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => BuildingKind::class,
            'ready_at' => 'datetime',
            'crowned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function isReady(): bool
    {
        return ! $this->ready_at->isFuture();
    }

    /** The level it works at: the one below while the next is going up. */
    public function workingLevel(): int
    {
        return $this->isReady() ? $this->level : $this->level - 1;
    }

    /** What it adds right now, as a fraction. */
    public function bonus(): float
    {
        return $this->kind->bonus($this->workingLevel());
    }

    /** How many are building its level now: the faction's crew and every member who helped. */
    public function builders(): int
    {
        return 1 + BuildingHelp::query()->where('building_id', $this->id)->where('level', $this->level)->count();
    }

    /**
     * What the game screens show about it, and whether this animal has
     * lent a hand with the level going up.
     *
     * @return array{id: int, kind: string, label: string, level: int, readyAt: string|null, does: string|null, builders: int|null, helped: bool}
     */
    public function summary(?Player $viewer = null): array
    {
        $goingUp = ! $this->isReady();

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->kind->label(),
            'level' => $this->level,
            'readyAt' => $goingUp ? $this->ready_at->toIso8601String() : null,
            'does' => $this->workingLevel() > 0 ? $this->kind->does($this->workingLevel()) : null,
            'builders' => $goingUp ? $this->builders() : null,
            'helped' => $goingUp && $viewer !== null && BuildingHelp::query()->where('building_id', $this->id)->where('level', $this->level)->where('player_id', $viewer->id)->exists(),
        ];
    }
}
