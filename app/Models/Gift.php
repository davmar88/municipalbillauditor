<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An item one animal gave another.
 *
 * @property int $id
 * @property int $item_id
 * @property int $from_player_id
 * @property int $to_player_id
 * @property Carbon|null $created_at
 */
#[Fillable(['item_id', 'from_player_id', 'to_player_id'])]
class Gift extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
