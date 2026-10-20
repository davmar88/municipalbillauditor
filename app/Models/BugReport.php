<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bug a player reported, with a photo or video if they added one.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $player_id
 * @property string $title
 * @property string $description
 * @property string|null $page Where in the game it happened
 * @property string|null $attachment_path On the private disk
 * @property string|null $attachment_mime
 * @property string|null $device The browser it came from
 * @property string $status open, fixed or closed
 * @property string|null $note The owner's note
 * @property Carbon $created_at
 * @property User $user
 * @property Player|null $player
 */
#[Fillable(['user_id', 'player_id', 'title', 'description', 'page', 'attachment_path', 'attachment_mime', 'device'])]
class BugReport extends Model
{
    public const STATUSES = ['open', 'fixed', 'closed'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
