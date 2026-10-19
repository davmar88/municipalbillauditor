<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A deal with one of Irongate's people: materials sold to the fence, or a
 * tip bought from the informer.
 *
 * @property int $id
 * @property int $player_id
 * @property string $kind fence or tip
 * @property string|null $material
 * @property int $amount
 * @property int $corn
 * @property int|null $warrant_id
 * @property Carbon|null $created_at
 */
#[Fillable(['player_id', 'kind', 'material', 'amount', 'corn', 'warrant_id'])]
class IrongateDeal extends Model
{
    public const UPDATED_AT = null;
}
