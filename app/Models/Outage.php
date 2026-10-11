<?php

namespace App\Models;

use App\Audit\Enums\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reported water or electricity outage in a suburb, entered by staff.
 *
 * @property int $id
 * @property int $metro_id
 * @property string $suburb
 * @property Service $service
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string|null $source
 */
#[Fillable(['metro_id', 'suburb', 'service', 'starts_on', 'ends_on', 'source'])]
class Outage extends Model
{
    protected function casts(): array
    {
        return [
            'service' => Service::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Metro, $this> */
    public function metro(): BelongsTo
    {
        return $this->belongsTo(Metro::class);
    }
}
