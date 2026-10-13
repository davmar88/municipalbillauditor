<?php

namespace App\Models;

use App\Audit\Money;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A card top-up through Paystack.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $user_id
 * @property PaymentPurpose $purpose
 * @property string $amount
 * @property string $currency
 * @property string $reference
 * @property PaymentStatus $status
 * @property string|null $gateway_message
 * @property Carbon|null $paid_at
 */
#[Fillable([
    'organisation_id', 'user_id', 'purpose', 'amount', 'currency',
    'reference', 'status', 'gateway_message', 'paid_at',
])]
class Payment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->reference ??= 'mba_'.Str::lower((string) Str::ulid());
        });
    }

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Paystack counts in cents. */
    public function amountInCents(): int
    {
        return Money::money($this->amount)->multipliedBy(100)->toScale(0)->toInt();
    }
}
