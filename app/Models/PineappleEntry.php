<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Pineapples a player bought or swapped, and why. The reference makes each
 * one happen once.
 *
 * @property int $id
 * @property int $player_id
 * @property int $amount
 * @property string $reason
 * @property string $reference
 */
#[Fillable(['player_id', 'amount', 'reason', 'reference'])]
class PineappleEntry extends Model
{
    public const UPDATED_AT = null;
}
