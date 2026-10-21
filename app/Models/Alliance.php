<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Two factions standing together: they can't raid each other and may
 * defend each other's land, until one of them breaks it.
 *
 * @property int $id
 * @property int $faction_id
 * @property int $ally_id
 * @property string $status
 * @property int|null $broken_by
 * @property Carbon|null $broken_at
 */
#[Fillable(['faction_id', 'ally_id', 'status', 'broken_by', 'broken_at'])]
class Alliance extends Model
{
    protected function casts(): array
    {
        return ['broken_at' => 'datetime'];
    }

    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return BelongsTo<Faction, $this> */
    public function ally(): BelongsTo
    {
        return $this->belongsTo(Faction::class, 'ally_id');
    }

    /** The other faction, seen from one side. */
    public function other(int $factionId): ?Faction
    {
        return $this->faction_id === $factionId ? $this->ally : $this->faction;
    }
}
