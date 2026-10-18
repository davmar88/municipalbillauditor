<?php

namespace App\Models;

use App\Enums\BuildingKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One game world: a farm of regions and up to a few hundred players.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
#[Fillable(['name', 'slug'])]
class Valley extends Model
{
    /** @return HasMany<Region, $this> */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }

    /** @return HasMany<Season, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /** @return HasMany<Faction, $this> */
    public function factions(): HasMany
    {
        return $this->hasMany(Faction::class);
    }

    /** @return HasMany<Player, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /** The season being played now. */
    public function currentSeason(): ?Season
    {
        return $this->seasons()->whereNull('ended_at')->latest('number')->first();
    }

    public function region(string $key): Region
    {
        return $this->regions()->where('key', $key)->firstOrFail();
    }

    /** The faction holding the Farmhouse rules the valley. */
    /**
     * The House: the faction that holds the Farmhouse and has a Head Office
     * standing there. Holding it isn't enough to rule.
     */
    public function house(): ?Faction
    {
        $farmhouse = $this->region('farmhouse');

        if ($farmhouse->faction_id === null) {
            return null;
        }

        $office = Building::query()
            ->where('region_id', $farmhouse->id)
            ->where('faction_id', $farmhouse->faction_id)
            ->where('kind', BuildingKind::HeadOffice)
            ->first();

        return $office !== null && $office->workingLevel() >= 1 ? $farmhouse->faction : null;
    }

    public static function main(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }
}
