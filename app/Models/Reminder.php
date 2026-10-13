<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A reminder we have sent about a bill or a dispute.
 *
 * @property int $id
 * @property string $kind
 */
#[Fillable(['organisation_id', 'subject_type', 'subject_id', 'kind', 'due_on', 'sent_at'])]
#[WithoutTimestamps]
class Reminder extends Model
{
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
