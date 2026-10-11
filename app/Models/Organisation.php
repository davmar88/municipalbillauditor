<?php

namespace App\Models;

use App\Enums\OrganisationKind;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer account: one household, one managing agent, or one business or
 * body corporate. Users belong to one organisation.
 *
 * @property int $id
 * @property OrganisationKind $kind
 * @property string $name
 * @property string $forwarding_code
 * @property string|null $unit_price
 * @property string|null $success_fee_rate
 */
#[Fillable(['kind', 'name', 'forwarding_code', 'unit_price', 'success_fee_rate'])]
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Organisation $organisation) {
            $organisation->forwarding_code ??= self::newForwardingCode();
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
            'unit_price' => 'decimal:2',
            'success_fee_rate' => 'decimal:4',
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

    public function successFeeRate(): string
    {
        return $this->success_fee_rate ?? (string) config('auditor.prices.success_fee_rate');
    }

    public function unitPrice(): string
    {
        return $this->unit_price ?? (string) config('auditor.prices.per_unit_month');
    }
}
