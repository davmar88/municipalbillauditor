<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A raid rule a member broke, for their faction's leader to see.
 *
 * @property int $id
 * @property int $faction_id
 * @property int $player_id
 * @property string $rule
 * @property string $detail
 * @property Carbon|null $created_at
 */
#[Fillable(['faction_id', 'player_id', 'rule', 'detail'])]
class FactionStrike extends Model
{
    public const UPDATED_AT = null;
}
