<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Anger, or calm, that fades: an exposed edit, a festival, a chant.
 *
 * @property int $id
 * @property int $season_id
 * @property int $amount
 * @property string $reason
 * @property Carbon $expires_at
 */
#[Fillable(['season_id', 'amount', 'reason', 'expires_at'])]
class UnrestEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
