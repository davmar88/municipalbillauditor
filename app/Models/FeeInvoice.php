<?php

namespace App\Models;

use App\Enums\FeeKind;
use App\Enums\FeeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fee we are owed. Success fees are only created once the money shows on
 * the customer's municipal account.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $dispute_id
 * @property FeeKind $kind
 * @property string $description
 * @property string|null $basis
 * @property string|null $rate
 * @property string $amount
 * @property FeeStatus $status
 * @property CarbonImmutable|null $period_month
 * @property CarbonImmutable|null $issued_on
 */
#[Fillable(['organisation_id', 'dispute_id', 'kind', 'description', 'basis', 'rate', 'amount', 'status', 'period_month', 'issued_on'])]
class FeeInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => FeeKind::class,
            'status' => FeeStatus::class,
            'basis' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'period_month' => 'immutable_date',
            'issued_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Dispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}
