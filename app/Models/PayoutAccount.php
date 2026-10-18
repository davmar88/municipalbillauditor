<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Where an animal wants its cash-outs sent, and who they are. The personal
 * details are encrypted, and the row goes with the account.
 *
 * @property int $id
 * @property int $player_id
 * @property string $legal_name
 * @property string $document_type id or passport
 * @property string $document_number
 * @property string $country Two letters, ZZ for another country
 * @property string $method paypal, paystack or bitcoin
 * @property string|null $paypal_email
 * @property string|null $bank_code
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $recipient_code Paystack's code for the bank account
 * @property string|null $bitcoin_address
 * @property Carbon|null $verified_at When Paystack confirmed the bank account is in this name and ID
 * @property Player $player
 */
#[Fillable(['player_id', 'legal_name', 'document_type', 'document_number', 'country', 'method', 'paypal_email', 'bank_code', 'bank_name', 'account_number', 'recipient_code', 'bitcoin_address', 'verified_at'])]
class PayoutAccount extends Model
{
    protected $hidden = ['legal_name', 'document_number', 'paypal_email', 'account_number'];

    protected function casts(): array
    {
        return [
            'legal_name' => 'encrypted',
            'document_number' => 'encrypted',
            'paypal_email' => 'encrypted',
            'account_number' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Where the money goes, safe to show: "PayPal, j•••@gmail.com", "Absa, ••••6789", "Bitcoin, bc1qar…mdq". */
    public function describe(): string
    {
        return match ($this->method) {
            'paypal' => 'PayPal, '.self::maskEmail((string) $this->paypal_email),
            'paystack' => ($this->bank_name ?? 'Bank').', ••••'.Str::substr((string) $this->account_number, -4),
            default => 'Bitcoin, '.Str::substr((string) $this->bitcoin_address, 0, 6).'…'.Str::substr((string) $this->bitcoin_address, -3),
        };
    }

    /** The ID or passport number with all but its last four characters hidden. */
    public function maskedDocument(): string
    {
        return '••••'.Str::substr($this->document_number, -4);
    }

    private static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($name, 0, 1).'•••@'.$domain;
    }
}
