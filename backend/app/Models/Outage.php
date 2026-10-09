<?php

namespace App\Models;

use App\Enums\Service;
use Database\Factories\OutageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $property_id
 * @property Service $service
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $notes
 */
#[Fillable(['service', 'starts_at', 'ends_at', 'notes'])]
class Outage extends Model
{
    /** @use HasFactory<OutageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => Service::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Outage>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->whereIn('property_id', Property::query()->select('id')->where('user_id', $user->id));
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
