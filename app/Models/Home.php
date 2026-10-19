<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An animal's own home: a Hut, a Cottage or a Fort at a spot on the map.
 *
 * @property int $id
 * @property int $player_id
 * @property int $region_id
 * @property float $x
 * @property float $y
 * @property int $level
 * @property Carbon|null $ready_at While a level goes up.
 * @property int|null $skin_item_id
 * @property Player $owner
 * @property Region $region
 * @property Item|null $skin
 */
#[Fillable(['player_id', 'region_id', 'x', 'y', 'level', 'ready_at'])]
class Home extends Model
{
    protected function casts(): array
    {
        return ['ready_at' => 'datetime', 'x' => 'float', 'y' => 'float'];
    }

    /** @return BelongsTo<Player, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function skin(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'skin_item_id');
    }

    /** Whether it stands, or a level is still going up. */
    public function building(): bool
    {
        return $this->ready_at !== null && $this->ready_at->isFuture();
    }
}
