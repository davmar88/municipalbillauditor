<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A private message its reader reported. Our team reads it and either
 * lets it stand or hides it and mutes the writer.
 *
 * @property int $id
 * @property int $direct_message_id
 * @property int $reporter_id
 * @property string $reason
 * @property string|null $note
 * @property string|null $outcome
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 * @property DirectMessage $message
 * @property Player $reporter
 */
#[Fillable(['direct_message_id', 'reporter_id', 'reason', 'note'])]
class MessageReport extends Model
{
    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<DirectMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class, 'direct_message_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'reporter_id');
    }
}
