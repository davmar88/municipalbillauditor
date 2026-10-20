<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line on the updates page: something new, or something fixed.
 *
 * @property int $id
 * @property Carbon $published_on
 * @property string $kind new or fix
 * @property string $title
 * @property string $body
 */
#[Fillable(['published_on', 'kind', 'title', 'body', 'commit'])]
class GameUpdate extends Model
{
    public const KINDS = ['new', 'fix'];

    protected function casts(): array
    {
        return ['published_on' => 'date'];
    }
}
