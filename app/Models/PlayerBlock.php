<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One animal refusing private messages from another. Neither can write to
 * the other while it stands.
 *
 * @property int $id
 * @property int $player_id
 * @property int $blocked_id
 */
#[Fillable(['player_id', 'blocked_id'])]
class PlayerBlock extends Model
{
    public const UPDATED_AT = null;
}
