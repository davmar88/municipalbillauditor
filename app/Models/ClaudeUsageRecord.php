<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The cost of one model's part in one Claude call.
 *
 * @property int $id
 * @property int|null $organisation_id
 * @property string $purpose
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string|null $cost_usd
 * @property string $usd_zar_rate
 * @property string|null $cost_zar
 * @property Carbon $created_at
 */
#[Table('claude_usages')]
#[Fillable([
    'organisation_id', 'subject_type', 'subject_id', 'purpose', 'model', 'input_tokens', 'output_tokens',
    'cache_read_tokens', 'cache_write_tokens', 'cost_usd', 'usd_zar_rate', 'cost_zar', 'created_at',
])]
#[WithoutTimestamps]
class ClaudeUsageRecord extends Model
{
    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'usd_zar_rate' => 'decimal:4',
            'cost_zar' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
