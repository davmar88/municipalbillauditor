<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A page someone opened or something they did on the site, for the owner's
 * Visitors page. Kept for 90 days.
 *
 * @property int $id
 * @property string $visitor
 * @property int|null $user_id
 * @property string|null $country
 * @property string $method
 * @property string|null $route
 * @property string $path
 * @property string|null $referrer
 * @property Carbon $created_at
 */
class Visit extends Model
{
    public const UPDATED_AT = null;

    public const KEEP_DAYS = 90;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
