<?php

namespace App\Models;

use App\Audit\Enums\CheckType;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\Service;
use App\Enums\FindingState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A possible error on a bill, stored from the engine's Finding.
 *
 * @property int $id
 * @property int $bill_id
 * @property int $number
 * @property CheckType $check_type
 * @property Service|null $service
 * @property Confidence $confidence
 * @property FindingState $state
 * @property string $title
 * @property string $explanation
 * @property string|null $amount
 * @property bool $amount_is_upper_bound
 * @property list<array{0: string, 1: string}> $evidence
 * @property string|null $calculation
 * @property string|null $question
 * @property list<array{0: string, 1: string}>|null $answer_options
 * @property string|null $answer
 * @property Carbon|null $answered_at
 * @property string|null $action
 * @property array<string, mixed>|null $data
 */
#[Fillable([
    'number', 'check_type', 'service', 'confidence', 'state', 'title', 'explanation',
    'amount', 'amount_is_upper_bound', 'evidence', 'calculation', 'question',
    'answer_options', 'answer', 'answered_at', 'action', 'data',
])]
class Finding extends Model
{
    protected function casts(): array
    {
        return [
            'check_type' => CheckType::class,
            'service' => Service::class,
            'confidence' => Confidence::class,
            'state' => FindingState::class,
            'amount' => 'decimal:2',
            'amount_is_upper_bound' => 'boolean',
            'evidence' => 'array',
            'answer_options' => 'array',
            'answered_at' => 'datetime',
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsToMany<Dispute, $this> */
    public function disputes(): BelongsToMany
    {
        return $this->belongsToMany(Dispute::class);
    }

    /** Still a live issue: not dismissed by the customer. */
    public function isOpen(): bool
    {
        return $this->state !== FindingState::Dismissed;
    }

    /** Waiting on the customer's answer before it can go in a dispute. */
    public function awaitingAnswer(): bool
    {
        return $this->confidence === Confidence::Question && $this->answer === null && $this->isOpen();
    }

    /** Ready to be part of a dispute letter. */
    public function disputable(): bool
    {
        return $this->isOpen() && ! $this->awaitingAnswer();
    }
}
