<?php

namespace App\Models;

use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bill_id
 * @property Service $service
 * @property string|null $meter_number
 * @property string|null $previous
 * @property string|null $current
 * @property string|null $consumption
 * @property ReadingType $reading_type
 * @property string|null $unit
 */
#[Fillable(['service', 'meter_number', 'previous', 'current', 'consumption', 'reading_type', 'unit'])]
#[WithoutTimestamps]
class MeterReading extends Model
{
    protected function casts(): array
    {
        return [
            'service' => Service::class,
            'reading_type' => ReadingType::class,
            'previous' => 'decimal:3',
            'current' => 'decimal:3',
            'consumption' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
