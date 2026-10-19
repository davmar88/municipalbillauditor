<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A faction mate who lent a hand raising one level of an animal's home.
 *
 * @property int $id
 * @property int $home_id
 * @property int $player_id
 * @property int $level
 */
#[Fillable(['home_id', 'player_id', 'level'])]
class HomeHelp extends Model
{
    public const UPDATED_AT = null;
}
