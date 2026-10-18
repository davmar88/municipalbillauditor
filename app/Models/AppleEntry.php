<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Apples a player earned or spent, and why. The reference makes each one
 * happen once.
 *
 * @property int $id
 * @property int $player_id
 * @property int $amount
 * @property string $reason
 * @property string $reference
 */
#[Fillable(['player_id', 'amount', 'reason', 'reference'])]
class AppleEntry extends Model
{
    public const UPDATED_AT = null;
}
