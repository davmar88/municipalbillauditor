<?php

namespace App\Models;

use App\Enums\DisputeEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $dispute_id
 * @property DisputeEventType $type
 * @property string|null $note
 * @property Carbon $occurred_at
 */
#[Fillable(['type', 'note', 'occurred_at'])]
class DisputeEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DisputeEventType::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}
