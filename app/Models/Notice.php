<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A piece of game news for one animal, shown under the bell.
 *
 * @property int $id
 * @property int $player_id
 * @property string $kind raid, battle, sale, faction, crime, law or uprising
 * @property string $text
 * @property string|null $url
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
#[Fillable(['player_id', 'kind', 'text', 'url', 'read_at'])]
class Notice extends Model
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
