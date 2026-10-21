<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something happening in the valley for an hour: the first animals to get
 * there and take part win.
 *
 * @property int $id
 * @property int $valley_id
 * @property string $kind
 * @property int $region_id
 * @property int $places
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
#[Fillable(['valley_id', 'kind', 'region_id', 'places', 'starts_at', 'ends_at'])]
class ValleyEvent extends Model
{
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return HasMany<ValleyEventEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ValleyEventEntry::class);
    }
}
