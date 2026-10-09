<?php

namespace App\Models;

use App\Enums\ReadingType;
use App\Enums\Service;
use App\Enums\Unit;
use Database\Factories\LineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bill_id
 * @property Service $service
 * @property string $description
 * @property string|null $tariff_category
 * @property ReadingType $reading_type
 * @property float|null $previous_reading
 * @property float|null $current_reading
 * @property float|null $consumption
 * @property Unit|null $unit
 * @property int $amount_cents
 */
#[Fillable(['service', 'description', 'tariff_category', 'reading_type', 'previous_reading', 'current_reading', 'consumption', 'unit', 'amount_cents'])]
class LineItem extends Model
{
    /** @use HasFactory<LineItemFactory> */
    use HasFactory;

    protected $attributes = [
        'reading_type' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => Service::class,
            'reading_type' => ReadingType::class,
            'unit' => Unit::class,
            'previous_reading' => 'float',
            'current_reading' => 'float',
            'consumption' => 'float',
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
