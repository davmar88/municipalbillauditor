<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Rosettes a player earned, and why. The reference makes each award happen
 * once, so a tick that runs twice never pays twice.
 *
 * @property int $id
 * @property int $player_id
 * @property int|null $season_id
 * @property int $amount
 * @property string $reason
 * @property string $reference
 */
#[Fillable(['player_id', 'season_id', 'amount', 'reason', 'reference'])]
class RosetteEntry extends Model
{
    public const UPDATED_AT = null;
}
