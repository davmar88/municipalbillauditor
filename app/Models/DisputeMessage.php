<?php

namespace App\Models;

use App\Enums\ReplyClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An email about a dispute: one we sent the municipality, or a reply that
 * came back to the dispute's own address.
 *
 * @property int $id
 * @property int $dispute_id
 * @property string $direction
 * @property string $kind
 * @property string|null $from_address
 * @property string|null $to_address
 * @property string|null $cc_address
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property ReplyClassification|null $classification
 * @property array<string, mixed>|null $reading
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
#[Fillable([
    'direction', 'kind', 'from_address', 'to_address', 'cc_address', 'subject', 'body',
    'message_id', 'in_reply_to', 'classification', 'reading', 'read_at',
])]
class DisputeMessage extends Model
{
    public const OUT = 'out';

    public const IN = 'in';

    protected function casts(): array
    {
        return [
            'classification' => ReplyClassification::class,
            'reading' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Dispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}
