<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A winner remembered for good: a season's top faction, its best animals,
 * and each week's top faction.
 *
 * @property int $id
 * @property int $valley_id
 * @property string $kind
 * @property int $season_number
 * @property int $place
 * @property int|null $player_id
 * @property int|null $faction_id
 * @property string $name
 * @property string|null $colour
 * @property int $points
 */
#[Fillable(['valley_id', 'kind', 'season_number', 'place', 'player_id', 'faction_id', 'name', 'colour', 'points', 'week_of'])]
class HallOfFame extends Model
{
    protected $table = 'hall_of_fame';

    protected function casts(): array
    {
        return ['week_of' => 'date'];
    }
}
