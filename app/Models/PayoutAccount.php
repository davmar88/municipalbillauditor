<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The PayPal email an animal wants its cash-outs sent to, and who they are.
 * The personal details are encrypted, and the row goes with the account.
 *
 * @property int $id
 * @property int $player_id
 * @property string $legal_name
 * @property string $document_type id or passport
 * @property string $document_number
 * @property string $country Two letters, ZZ for another country
 * @property string $method paypal
 * @property string $paypal_email
 * @property Player $player
 */
#[Fillable(['player_id', 'legal_name', 'document_type', 'document_number', 'country', 'method', 'paypal_email'])]
class PayoutAccount extends Model
{
    protected $hidden = ['legal_name', 'document_number', 'paypal_email'];

    protected function casts(): array
    {
        return [
            'legal_name' => 'encrypted',
            'document_number' => 'encrypted',
            'paypal_email' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Where the money goes, safe to show: "PayPal, j•••@gmail.com". */
    public function describe(): string
    {
        return 'PayPal, '.self::maskEmail((string) $this->paypal_email);
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
