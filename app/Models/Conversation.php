<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Private messages between two animals. Each pair has one conversation,
 * stored with the lower player id first.
 *
 * @property int $id
 * @property int $player_one_id
 * @property int $player_two_id
 * @property int $started_by_id
 * @property int|null $last_message_id
 * @property Player $playerOne
 * @property Player $playerTwo
 * @property DirectMessage|null $lastMessage
 */
#[Fillable(['player_one_id', 'player_two_id', 'started_by_id'])]
class Conversation extends Model
{
    /** @return BelongsTo<Player, $this> */
    public function playerOne(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_one_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function playerTwo(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_two_id');
    }

    /** @return BelongsTo<DirectMessage, $this> */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class, 'last_message_id');
    }

    /** @return HasMany<DirectMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class);
    }

    /** The animal at the other end from this one. */
    public function other(Player $player): Player
    {
        return $this->player_one_id === $player->id ? $this->playerTwo : $this->playerOne;
    }
}
