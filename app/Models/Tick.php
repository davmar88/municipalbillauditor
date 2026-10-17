<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A half-hour in a season. The tick number makes each one run once.
 *
 * @property int $id
 * @property int $season_id
 * @property int $number
 * @property Carbon $ran_at
 */
#[Fillable(['season_id', 'number', 'ran_at'])]
class Tick extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['ran_at' => 'datetime'];
    }
}
