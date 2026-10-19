<?php

namespace App\Models;

use App\Enums\FactionRole;
use App\Enums\Species;
use App\Game\Levels;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A user's animal in the game.
 *
 * @property int $id
 * @property int $user_id
 * @property int $valley_id
 * @property int|null $region_id Where the animal stands; null means the Paddock.
 * @property float|null $pos_x Where it stands on the map, when not walking
 * @property float|null $pos_y
 * @property float|null $walk_from_x The walk it is on, from where to where
 * @property float|null $walk_from_y
 * @property float|null $walk_to_x
 * @property float|null $walk_to_y
 * @property Carbon|null $walk_started_at
 * @property Carbon|null $walk_arrives_at
 * @property string $name
 * @property Species $species
 * @property string $language
 * @property int $rosettes
 * @property int $market_skill
 * @property int $creativity_skill
 * @property int $corn
 * @property int $pineapples What real money buys; they swap for packs of Apples
 * @property int $apples The premium currency, bought in packs
 * @property Carbon|null $orchard_until When Orchard membership ends
 * @property Carbon|null $orchard_refill_on The valley day the member last took their free refill
 * @property array<string, int> $materials
 * @property int $horseshoes
 * @property Carbon $horseshoes_at
 * @property int|null $camp_level Walking off to set up camp: the home that goes up on arrival, 0 a Tent or 1 a Hut.
 * @property int|null $faction_id
 * @property FactionRole|null $faction_role
 * @property Carbon|null $faction_joined_at
 * @property Carbon|null $shield_until
 * @property Carbon|null $home_closed_until
 * @property Carbon|null $home_burned_at
 * @property Carbon|null $pound_until
 * @property Carbon|null $inside_home_at When it went inside its home to hide.
 * @property Carbon|null $left_faction_at When it last left or was thrown out of a faction.
 * @property int $wanted
 * @property Carbon|null $muted_until Our team stopped the animal writing until then.
 * @property Carbon|null $last_visit_on
 * @property Carbon $adult_confirmed_at
 * @property User $user
 * @property Valley $valley
 * @property Faction|null $faction
 * @property Region|null $region
 * @property PayoutAccount|null $payoutAccount
 */
#[Fillable(['user_id', 'valley_id', 'region_id', 'name', 'species', 'language', 'materials', 'corn', 'horseshoes', 'horseshoes_at', 'shield_until', 'adult_confirmed_at'])]
class Player extends Model
{
    public const MATERIALS = ['straw', 'wool', 'wood', 'clay', 'dye', 'stone', 'eggs'];

    protected function casts(): array
    {
        return [
            'species' => Species::class,
            'faction_role' => FactionRole::class,
            'materials' => 'array',
            'horseshoes_at' => 'datetime',
            'faction_joined_at' => 'datetime',
            'shield_until' => 'datetime',
            'home_closed_until' => 'datetime',
            'home_burned_at' => 'datetime',
            'pound_until' => 'datetime',
            'muted_until' => 'datetime',
            'left_faction_at' => 'datetime',
            'inside_home_at' => 'datetime',
            'last_visit_on' => 'date',
            'adult_confirmed_at' => 'datetime',
            'orchard_until' => 'datetime',
            'orchard_refill_on' => 'date',
            'pos_x' => 'float',
            'pos_y' => 'float',
            'walk_from_x' => 'float',
            'walk_from_y' => 'float',
            'walk_to_x' => 'float',
            'walk_to_y' => 'float',
            'walk_started_at' => 'datetime',
            'walk_arrives_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Valley, $this> */
    public function valley(): BelongsTo
    {
        return $this->belongsTo(Valley::class);
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** Where the animal stands: its region, or the Paddock it started in. */
    public function location(): Region
    {
        return $this->region ?? $this->valley->region('paddock');
    }

    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return HasMany<Item, $this> The items the animal still has: used ones are gone. */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class)->whereNull('used_at');
    }

    /** @return HasMany<ItemDesign, $this> */
    public function designs(): HasMany
    {
        return $this->hasMany(ItemDesign::class);
    }

    /** @return HasOne<PayoutAccount, $this> Where the animal's cash-outs go. */
    public function payoutAccount(): HasOne
    {
        return $this->hasOne(PayoutAccount::class);
    }

    /** @return HasMany<RosetteEntry, $this> */
    public function rosetteEntries(): HasMany
    {
        return $this->hasMany(RosetteEntry::class);
    }

    public function level(): int
    {
        return Levels::forRosettes($this->rosettes);
    }

    public function isLoneWolf(): bool
    {
        return $this->faction_id === null;
    }

    public function inPound(): bool
    {
        return $this->pound_until !== null && $this->pound_until->isFuture();
    }

    public function isShielded(): bool
    {
        return $this->shield_until !== null && $this->shield_until->isFuture();
    }

    public function homeIsClosed(): bool
    {
        return $this->home_closed_until !== null && $this->home_closed_until->isFuture();
    }

    /** Whether the animal is an Orchard member, with its badge and perks. */
    public function inOrchard(): bool
    {
        return $this->orchard_until !== null && $this->orchard_until->isFuture();
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function decides(): bool
    {
        return $this->faction_role?->decides() ?? false;
    }

    public function material(string $name): int
    {
        return (int) ($this->materials[$name] ?? 0);
    }
}
