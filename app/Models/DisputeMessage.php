<?php

namespace App\Models;

use App\Enums\ReplyClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

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
 * @property list<array{path: string, name: string, mime: string, size: int}>|null $attachments
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property ReplyClassification|null $classification
 * @property array<string, mixed>|null $reading
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
#[Fillable([
    'direction', 'kind', 'from_address', 'to_address', 'cc_address', 'subject', 'body', 'attachments',
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
            'attachments' => 'array',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Deleting a reply deletes its files too.
        static::deleting(function (DisputeMessage $message) {
            $disk = Storage::disk(config('auditor.bill_disk'));

            foreach ($message->attachments ?? [] as $file) {
                $disk->delete($file['path']);
            }
        });
    }

    /** The sender's address without the display name. */
    public function senderEmail(): ?string
    {
        $from = (string) $this->from_address;

        if (preg_match('/<([^>]+)>/', $from, $match)) {
            $from = $match[1];
        }

        $from = strtolower(trim($from));

        return filter_var($from, FILTER_VALIDATE_EMAIL) !== false ? $from : null;
    }

    /** @return BelongsTo<Dispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}
