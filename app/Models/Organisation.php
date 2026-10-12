<?php

namespace App\Models;

use App\Audit\Money;
use App\Enums\OrganisationKind;
use App\Services\Billing\Wallet;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A customer account: one household, one managing agent, or one business or
 * body corporate. Users belong to one organisation.
 *
 * @property int $id
 * @property OrganisationKind $kind
 * @property string $name
 * @property string $forwarding_code
 * @property string|null $monthly_fee
 * @property string $credit_balance
 * @property string|null $card_authorization
 * @property string|null $card_last4
 * @property string|null $card_brand
 * @property string|null $card_expiry
 * @property string|null $card_email
 * @property Carbon|null $card_saved_at
 */
#[Fillable(['kind', 'name', 'forwarding_code', 'monthly_fee'])]
#[Hidden(['card_authorization'])]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Organisation $organisation) {
            $organisation->forwarding_code ??= self::newForwardingCode();
        });

        // New accounts start with the welcome credit, when there is one.
        static::created(function (Organisation $organisation) {
            app(Wallet::class)->welcome($organisation);
        });
    }

    public static function newForwardingCode(): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (self::where('forwarding_code', $code)->exists());

        return $code;
    }

    protected function casts(): array
    {
        return [
            'kind' => OrganisationKind::class,
            'monthly_fee' => 'decimal:2',
            'credit_balance' => 'decimal:2',
            // A reusable Paystack authorization: enough to charge the card.
            'card_authorization' => 'encrypted',
            'card_saved_at' => 'datetime',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** @return HasMany<Dispute, $this> */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /** @return HasMany<FeeInvoice, $this> */
    public function feeInvoices(): HasMany
    {
        return $this->hasMany(FeeInvoice::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Delete the organisation and everything it holds. Bills go one by one
     * so their files are deleted from the private disk too.
     */
    public function purge(): void
    {
        $this->bills()->get()->each->delete();
        $this->delete();
    }

    /** The address customers forward e-bills to. */
    public function forwardingAddress(): string
    {
        return $this->forwarding_code.'@'.config('auditor.inbound_email_domain');
    }

    /** Agents and businesses pay a monthly fee; households pay per job. */
    public function paysMonthly(): bool
    {
        return $this->kind !== OrganisationKind::Household;
    }

    /**
     * The monthly fee this organisation pays: negotiated, or the standard
     * fee for its kind. Null for households.
     */
    public function monthlyFee(): ?string
    {
        if (! $this->paysMonthly()) {
            return null;
        }

        return (string) Money::money($this->monthly_fee ?? match ($this->kind) {
            OrganisationKind::Agent => (string) config('auditor.prices.agent_month'),
            default => (string) config('auditor.prices.business_month'),
        });
    }
}
