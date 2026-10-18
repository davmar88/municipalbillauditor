<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Apples an animal is cashing out, from asking through the owner's approval
 * to the money arriving. The Apples leave the animal's balance when it asks,
 * and come back if the owner turns it down or the payout fails.
 *
 * @property int $id
 * @property int|null $player_id Null once the account is deleted
 * @property string $reference Ours, sent to PayPal
 * @property int $apples
 * @property string $method paypal
 * @property string $currency USD
 * @property int $amount In cents
 * @property array{name: string, label: string, document: string, email: string} $destination
 * @property string $status pending, sending, paid, failed or rejected
 * @property string|null $provider_id PayPal's payout batch
 * @property string|null $note What went wrong, or why the owner turned it down
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property Carbon $created_at
 * @property Player|null $player
 * @property User|null $reviewer
 */
#[Fillable(['player_id', 'reference', 'apples', 'method', 'currency', 'amount', 'destination', 'status'])]
class Cashout extends Model
{
    protected $hidden = ['destination'];

    protected function casts(): array
    {
        return [
            'destination' => 'encrypted:array',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** "$18.00". */
    public function price(): string
    {
        return '$'.number_format($this->amount / 100, 2, '.', ' ');
    }
}
