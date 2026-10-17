<?php

namespace App\Models;

use App\Enums\EntryMode;
use App\Enums\FactionRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of players that holds land, and rules the valley while it holds
 * the Farmhouse. Each faction sets its own rules for who may join.
 *
 * @property int $id
 * @property int $valley_id
 * @property string $name
 * @property string $colour
 * @property string|null $motto
 * @property int $treasury
 * @property EntryMode $entry_mode
 * @property int $min_level
 * @property array<int, string>|null $species
 * @property int $entry_fee
 * @property bool $accepts_criminals
 */
#[Fillable(['valley_id', 'name', 'colour', 'motto', 'entry_mode', 'min_level', 'species', 'entry_fee', 'accepts_criminals'])]
class Faction extends Model
{
    /** The colours factions pick from, chosen to read on the night map. */
    public const COLOURS = ['#41d1ff', '#ff8fb1', '#7ee787', '#ffd33d', '#f0883e', '#a5d6ff', '#d2a8ff', '#56d4dd', '#ffa198', '#e3b341'];

    protected function casts(): array
    {
        return [
            'entry_mode' => EntryMode::class,
            'species' => 'array',
            'accepts_criminals' => 'boolean',
        ];
    }

    /** @return BelongsTo<Valley, $this> */
    public function valley(): BelongsTo
    {
        return $this->belongsTo(Valley::class);
    }

    /** @return HasMany<Player, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /** @return HasMany<Region, $this> */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }

    /** @return HasMany<FactionJoinRequest, $this> */
    public function joinRequests(): HasMany
    {
        return $this->hasMany(FactionJoinRequest::class);
    }

    public function leader(): ?Player
    {
        return $this->members()->where('faction_role', FactionRole::Leader)->first();
    }

    public function isHouse(): bool
    {
        return $this->regions()->where('key', 'farmhouse')->exists();
    }
}
