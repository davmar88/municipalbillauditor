<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Corn, Apples, Pineapples or a material one animal sent another.
 *
 * @property int $id
 * @property int $from_player_id
 * @property int $to_player_id
 * @property string $what corn, apples, pineapples or a material
 * @property int $amount
 */
#[Fillable(['from_player_id', 'to_player_id', 'what', 'amount'])]
class Send extends Model
{
    public const UPDATED_AT = null;
}
