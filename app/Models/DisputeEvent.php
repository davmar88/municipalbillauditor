<?php

namespace App\Models;

use App\Enums\DisputeEventKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dispute_id
 * @property int|null $user_id
 * @property DisputeEventKind $kind
 * @property CarbonImmutable $happened_on
 * @property string|null $note
 */
#[Fillable(['user_id', 'kind', 'happened_on', 'note'])]
class DisputeEvent extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => DisputeEventKind::class,
            'happened_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Dispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
