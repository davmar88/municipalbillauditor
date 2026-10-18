<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A member's hand in building a level of their faction's building.
 *
 * @property int $id
 * @property int $building_id
 * @property int $player_id
 * @property int $level
 */
#[Fillable(['building_id', 'player_id', 'level'])]
class BuildingHelp extends Model
{
    public const UPDATED_AT = null;
}
