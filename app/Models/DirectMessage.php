<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A private message from one animal to another. Our team can hide one
 * after it is reported.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_id
 * @property int $recipient_id
 * @property string $body
 * @property string|null $translation
 * @property string|null $translation_language
 * @property Carbon|null $read_at
 * @property Carbon|null $hidden_at
 * @property Carbon $created_at
 * @property Conversation $conversation
 * @property Player $sender
 * @property Player $recipient
 * @property MessageReport|null $report
 */
#[Fillable(['conversation_id', 'sender_id', 'recipient_id', 'body'])]
class DirectMessage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'sender_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'recipient_id');
    }

    /** @return HasOne<MessageReport, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(MessageReport::class);
    }
}
