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
 * @property string $kind bug, or cheat for a report about another player
 * @property int|null $reported_player_id Who a cheating report is about
 * @property string|null $rule The fair play rule a cheating report says was broken
 * @property Player|null $reported
 * @property Carbon $created_at
 * @property User $user
 * @property Player|null $player
 */
#[Fillable(['user_id', 'player_id', 'title', 'description', 'page', 'attachment_path', 'attachment_mime', 'device', 'kind', 'reported_player_id', 'rule'])]
class BugReport extends Model
{
    public const STATUSES = ['open', 'fixed', 'closed'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function reported(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'reported_player_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
