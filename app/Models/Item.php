<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One numbered copy of a design, owned by a player.
 *
 * @property int $id
 * @property int $item_design_id
 * @property int $player_id
 * @property int $serial
 * @property Carbon|null $used_at When its owner used its power up.
 * @property ItemDesign $design
 * @property Player $owner
 */
#[Fillable(['item_design_id', 'player_id', 'serial'])]
class Item extends Model
{
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    /** @return BelongsTo<ItemDesign, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(ItemDesign::class, 'item_design_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /** @return HasOne<Listing, $this> */
    public function openListing(): HasOne
    {
        return $this->hasOne(Listing::class)->whereNull('sold_at');
    }
}
