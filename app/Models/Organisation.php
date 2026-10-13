<?php

namespace App\Models;

use App\Enums\OrganisationKind;
use App\Services\Billing\Wallet;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A customer account: one household, one managing agent, or one business or
 * body corporate. A person can belong to several and switch between them;
 * each keeps its own credit, bills and forwarding address. Every kind pays
 * as it goes, per job, from prepaid credit.
 *
 * @property int $id
 * @property OrganisationKind $kind
 * @property string $name
 * @property string $forwarding_code
 * @property string $credit_balance
 * @property Carbon|null $dispute_mandate_at
 * @property int|null $dispute_mandate_user_id
 * @property bool $auto_send_disputes
 */
#[Fillable(['kind', 'name', 'forwarding_code'])]
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
            'credit_balance' => 'decimal:2',
            'dispute_mandate_at' => 'datetime',
            'auto_send_disputes' => 'boolean',
        ];
    }

    /**
     * Everyone on the account, whichever account they are working in now:
     * they all get its emails and can answer its disputes.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
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

    /** Whether the customer has let us send disputes for them. */
    public function hasMandate(): bool
    {
        return $this->dispute_mandate_at !== null;
    }
}
