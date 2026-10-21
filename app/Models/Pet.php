<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An animal's pet: a dog that sniffs out more when gathering, or a horse
 * that carries it faster. Fed every day, it grows up to level 5.
 *
 * @property int $id
 * @property int $player_id
 * @property string $kind
 * @property string $name
 * @property int $level
 * @property int $feeds
 * @property Carbon|null $fed_at
 */
#[Fillable(['player_id', 'kind', 'name', 'level', 'feeds', 'fed_at'])]
class Pet extends Model
{
    protected function casts(): array
    {
        return ['fed_at' => 'datetime', 'level' => 'integer', 'feeds' => 'integer'];
    }
}
