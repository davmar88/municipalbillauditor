<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Skill points a player earned, and why. Like Rosettes, the reference makes
 * each award happen once.
 *
 * @property int $id
 * @property int $player_id
 * @property int|null $season_id
 * @property string $skill
 * @property int $amount
 * @property string $reason
 * @property string $reference
 */
#[Fillable(['player_id', 'season_id', 'skill', 'amount', 'reason', 'reference'])]
class SkillEntry extends Model
{
    public const UPDATED_AT = null;
}
