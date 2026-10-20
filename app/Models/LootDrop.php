<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a dead animal carried, lying where it was scattered until someone
 * picks it up or the crows have it.
 *
 * @property int $id
 * @property int $valley_id
 * @property int|null $player_id
 * @property float $x
 * @property float $y
 * @property int $corn
 * @property array<string, int>|null $materials
 * @property Carbon $expires_at
 * @property int|null $picked_by
 * @property Carbon|null $picked_at
 */
class LootDrop extends Model
{
    protected $fillable = ['valley_id', 'player_id', 'x', 'y', 'corn', 'materials', 'expires_at'];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'materials' => 'array',
            'expires_at' => 'datetime',
            'picked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Lying on the map now. */
    public function scopeLying($query)
    {
        return $query->whereNull('picked_at')->where('expires_at', '>', now());
    }
}
