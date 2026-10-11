<?php

namespace App\Models;

use App\Audit\Enums\Service;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a bill. Credits are negative amounts.
 *
 * @property int $id
 * @property int $bill_id
 * @property Service $service
 * @property string $description
 * @property string $amount
 * @property string|null $quantity
 * @property string|null $unit
 */
#[Fillable(['service', 'description', 'amount', 'quantity', 'unit'])]
#[WithoutTimestamps]
class Charge extends Model
{
    protected function casts(): array
    {
        return [
            'service' => Service::class,
            'amount' => 'decimal:2',
            'quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
