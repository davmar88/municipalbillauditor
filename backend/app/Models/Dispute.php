<?php

namespace App\Models;

use App\Enums\DisputeChannel;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $bill_id
 * @property DisputeStatus $status
 * @property list<int> $finding_ids
 * @property int $amount_disputed_cents
 * @property string $letter_subject
 * @property string $letter_body
 * @property DisputeChannel|null $channel
 * @property string|null $municipality_reference
 * @property Carbon|null $submitted_at
 * @property Carbon|null $response_due_at
 * @property int $escalation_level
 * @property Carbon|null $escalated_at
 * @property int|null $outcome_amount_cents
 * @property Carbon|null $resolved_at
 * @property-read Bill $bill
 */
#[Fillable(['status', 'finding_ids', 'amount_disputed_cents', 'letter_subject', 'letter_body', 'channel', 'municipality_reference', 'submitted_at', 'response_due_at', 'escalation_level', 'escalated_at', 'outcome_amount_cents', 'resolved_at'])]
class Dispute extends Model
{
    protected $attributes = [
        'status' => 'draft',
        'escalation_level' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'channel' => DisputeChannel::class,
            'finding_ids' => 'array',
            'amount_disputed_cents' => 'integer',
            // The letter contains the account number and address: encrypted.
            'letter_subject' => 'encrypted',
            'letter_body' => 'encrypted',
            'submitted_at' => 'datetime',
            'response_due_at' => 'datetime',
            'escalation_level' => 'integer',
            'escalated_at' => 'datetime',
            'outcome_amount_cents' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Dispute>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->whereIn('bill_id', Bill::query()->select('id')->ownedBy($user));
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * @return HasMany<DisputeEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DisputeEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * The next escalation step after the current level, with its due date,
     * or null when draft/resolved/rejected or there is no further step.
     *
     * @return array{name: string, description: string, due_at: Carbon|null}|null
     */
    public function nextStep(): ?array
    {
        if ($this->status === DisputeStatus::Draft || $this->status->isFinal()) {
            return null;
        }

        $step = collect($this->bill->property->metroConfig()['escalation_steps'])
            ->firstWhere('level', $this->escalation_level + 1);

        if ($step === null) {
            return null;
        }

        return [
            'name' => $step['name'],
            'description' => $step['description'],
            'due_at' => $this->response_due_at,
        ];
    }

    public function hasNextStep(): bool
    {
        return collect($this->bill->property->metroConfig()['escalation_steps'])
            ->contains('level', $this->escalation_level + 1);
    }
}
