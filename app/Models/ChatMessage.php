<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat message in a faction's own channel, a channel between two
 * factions, or the valley square everyone can read.
 *
 * @property int $id
 * @property int $valley_id
 * @property string $channel
 * @property int $player_id
 * @property string $body
 * @property Carbon $created_at
 * @property Player $player
 */
#[Fillable(['valley_id', 'channel', 'player_id', 'body'])]
class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return HasMany<ChatTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ChatTranslation::class);
    }
}
