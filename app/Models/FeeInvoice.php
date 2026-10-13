<?php

namespace App\Models;

use App\Enums\FeeKind;
use App\Enums\FeeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fee we are owed: an agent's or business's monthly fee, raised on the
 * 1st of each month, at most once per organisation per month.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $dispute_id
 * @property FeeKind $kind
 * @property string $description
 * @property string $amount
 * @property FeeStatus $status
 * @property CarbonImmutable|null $period_month
 * @property CarbonImmutable|null $issued_on
 * @property Carbon|null $paid_at
 */
#[Fillable(['organisation_id', 'kind', 'description', 'amount', 'status', 'period_month', 'issued_on', 'paid_at'])]
class FeeInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => FeeKind::class,
            'status' => FeeStatus::class,
            'amount' => 'decimal:2',
            'period_month' => 'immutable_date',
            'issued_on' => 'immutable_date',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function isPaid(): bool
    {
        return $this->status === FeeStatus::Paid;
    }
}
