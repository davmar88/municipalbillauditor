<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pack of Pineapples an animal is buying, from the moment they pick it
 * until PayPal says the money arrived.
 *
 * @property int $id
 * @property int|null $player_id Null once the account is deleted
 * @property string $pack
 * @property int $pineapples
 * @property string $provider paypal, or paystack on a purchase from before PayPal only
 * @property string $currency USD, or ZAR on a Paystack purchase
 * @property int $amount In cents
 * @property string $reference Ours, sent to the provider
 * @property string|null $provider_id PayPal's order id
 * @property string $status pending, paid, failed, or review when the money doesn't match the pack
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Player|null $player
 */
#[Fillable(['player_id', 'pack', 'pineapples', 'provider', 'currency', 'amount', 'reference', 'provider_id', 'status', 'paid_at'])]
class ApplePurchase extends Model
{
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** "$5.70", or "R95.00" for a Paystack purchase. */
    public function price(): string
    {
        return ($this->currency === 'ZAR' ? 'R' : '$').number_format($this->amount / 100, 2, '.', ' ');
    }
}
