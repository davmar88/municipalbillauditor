<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A warrant the House put out for an animal's arrest, with the bounty a
 * bounty hunter collects for catching them.
 *
 * @property int $id
 * @property int $season_id
 * @property int $player_id The wanted animal
 * @property int|null $faction_id The House that issued it
 * @property int $bounty
 * @property string $status open, served or lapsed
 * @property int|null $hunter_id
 * @property Carbon|null $escaped_until
 * @property Carbon|null $served_at
 * @property Player $wanted
 * @property Faction|null $faction
 * @property Player|null $hunter
 */
#[Fillable(['season_id', 'player_id', 'faction_id', 'bounty', 'status'])]
class Warrant extends Model
{
    protected function casts(): array
    {
        return [
            'escaped_until' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function wanted(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /** @return BelongsTo<Faction, $this> */
    public function faction(): BelongsTo
    {
        return $this->belongsTo(Faction::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function hunter(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'hunter_id');
    }
}
