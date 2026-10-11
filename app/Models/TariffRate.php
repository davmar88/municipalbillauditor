<?php

namespace App\Models;

use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A metro's price per unit for one tariff category, used to cost a wrong
 * tariff. Real tariffs are stepped; one representative rate per category
 * keeps the estimate cautious and explainable.
 *
 * @property int $id
 * @property int $metro_id
 * @property TariffCategory $category
 * @property Service $service
 * @property string $rate
 * @property string $unit
 * @property CarbonImmutable|null $effective_from
 * @property string|null $source
 * @property bool $verified
 */
#[Fillable(['metro_id', 'category', 'service', 'rate', 'unit', 'effective_from', 'source', 'verified'])]
class TariffRate extends Model
{
    protected function casts(): array
    {
        return [
            'category' => TariffCategory::class,
            'service' => Service::class,
            'rate' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'verified' => 'boolean',
        ];
    }

    /** @return BelongsTo<Metro, $this> */
    public function metro(): BelongsTo
    {
        return $this->belongsTo(Metro::class);
    }
}
