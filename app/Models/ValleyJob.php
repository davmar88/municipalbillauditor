<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A job a valley animal pinned to the board: the first animal to do it is paid.
 *
 * @property int $id
 * @property int $valley_id
 * @property string $kind
 * @property string $poster
 * @property int|null $target_faction_id
 * @property int|null $region_id
 * @property string|null $material
 * @property int $amount
 * @property int $reward_corn
 * @property int $reward_apples
 * @property int $reward_rosettes
 * @property int|null $done_by
 * @property Carbon|null $done_at
 * @property Carbon $expires_at
 */
#[Fillable(['valley_id', 'kind', 'poster', 'target_faction_id', 'region_id', 'material', 'amount', 'reward_corn', 'reward_apples', 'reward_rosettes', 'done_by', 'done_at', 'expires_at'])]
class ValleyJob extends Model
{
    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Faction, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Faction::class, 'target_faction_id');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function doer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'done_by');
    }
}
