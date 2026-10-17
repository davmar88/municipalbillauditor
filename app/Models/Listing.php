<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An item on a shelf in a player's shop, priced in Corn.
 *
 * @property int $id
 * @property int $item_id
 * @property int $player_id
 * @property int $price
 * @property int $levy
 * @property int|null $buyer_player_id
 * @property Carbon|null $sold_at
 * @property Item $item
 * @property Player $seller
 */
#[Fillable(['item_id', 'player_id', 'price'])]
class Listing extends Model
{
    protected function casts(): array
    {
        return ['sold_at' => 'datetime'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
