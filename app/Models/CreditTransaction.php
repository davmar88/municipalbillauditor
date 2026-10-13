<?php

namespace App\Models;

use App\Enums\CreditKind;
use App\Enums\Job;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One line in an organisation's prepaid credit ledger. Rows are only ever
 * added, by the Wallet, never changed.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $user_id
 * @property CreditKind $kind
 * @property Job|null $job
 * @property string $amount
 * @property string $balance_after
 * @property bool $covered_by_plan
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $description
 * @property string $reference
 * @property Carbon $created_at
 */
#[Fillable([
    'organisation_id', 'user_id', 'kind', 'job', 'amount', 'balance_after', 'covered_by_plan',
    'subject_type', 'subject_id', 'description', 'reference',
])]
class CreditTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => CreditKind::class,
            'job' => Job::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'covered_by_plan' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
