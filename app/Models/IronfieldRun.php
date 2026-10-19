<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One go at an Ironfield mission: where, how deep, how many horseshoes went
 * in, and whether the animal got away with it.
 *
 * @property int $id
 * @property int $player_id
 * @property int $region_id
 * @property int $depth
 * @property int $horseshoes
 * @property bool $won
 * @property Carbon|null $created_at
 */
#[Fillable(['player_id', 'region_id', 'depth', 'horseshoes', 'won'])]
class IronfieldRun extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['won' => 'boolean'];
    }
}
