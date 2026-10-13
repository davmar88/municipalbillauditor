<?php

namespace App\Models;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Deadlines\CaseFacts;
use App\Audit\Deadlines\CaseStatus;
use App\Audit\Deadlines\Stage;
use App\Audit\Money;
use App\Enums\DisputeMode;
use Carbon\CarbonImmutable;
use Database\Factories\DisputeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A dispute (a "claim" for businesses): the letter, the deadlines and what
 * has happened since it was lodged.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $property_id
 * @property int $bill_id
 * @property DisputeMode $mode
 * @property string $letter_subject
 * @property string $letter_body
 * @property string $disputed_total
 * @property string|null $undisputed_amount
 * @property CarbonImmutable|null $dispute_deadline
 * @property CarbonImmutable|null $submitted_on
 * @property CarbonImmutable|null $lodged_on
 * @property string|null $reference
 * @property CarbonImmutable|null $acknowledged_on
 * @property CarbonImmutable|null $info_requested_on
 * @property CarbonImmutable|null $info_reminded_on
 * @property CarbonImmutable|null $info_answered_on
 * @property CarbonImmutable|null $reply_received_on
 * @property CarbonImmutable|null $escalated_councillor_on
 * @property CarbonImmutable|null $escalated_public_protector_on
 * @property CarbonImmutable|null $tariff_fixed_on
 * @property string|null $monthly_saving
 * @property CarbonImmutable|null $credit_on
 * @property string|null $credit_amount
 * @property int|null $credit_bill_id
 * @property CarbonImmutable|null $closed_on
 * @property string|null $close_reason
 * @property string|null $reply_token
 * @property string|null $sent_to
 * @property string|null $sent_to_source
 * @property string|null $held_reason
 * @property Carbon|null $held_since
 * @property CarbonImmutable|null $followed_up_on
 * @property CarbonImmutable|null $escalated_manager_on
 * @property CarbonImmutable|null $public_protector_draft_on
 * @property Carbon $created_at
 */
#[Fillable([
    'property_id', 'bill_id', 'mode', 'letter_subject', 'letter_body', 'disputed_total',
    'undisputed_amount', 'dispute_deadline', 'submitted_on', 'lodged_on', 'reference', 'acknowledged_on',
    'reply_received_on', 'escalated_councillor_on', 'escalated_public_protector_on',
    'tariff_fixed_on', 'monthly_saving', 'credit_on', 'credit_amount',
    'credit_bill_id', 'closed_on', 'close_reason', 'reply_token', 'sent_to', 'sent_to_source',
    'held_reason', 'held_since', 'followed_up_on', 'escalated_manager_on', 'public_protector_draft_on',
    'info_requested_on', 'info_reminded_on', 'info_answered_on',
])]
class Dispute extends Model
{
    /** @use HasFactory<DisputeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'mode' => DisputeMode::class,
            'disputed_total' => 'decimal:2',
            'undisputed_amount' => 'decimal:2',
            'dispute_deadline' => 'immutable_date',
            'submitted_on' => 'immutable_date',
            'lodged_on' => 'immutable_date',
            'acknowledged_on' => 'immutable_date',
            'reply_received_on' => 'immutable_date',
            'escalated_councillor_on' => 'immutable_date',
            'escalated_public_protector_on' => 'immutable_date',
            'tariff_fixed_on' => 'immutable_date',
            'monthly_saving' => 'decimal:2',
            'credit_on' => 'immutable_date',
            'credit_amount' => 'decimal:2',
            'closed_on' => 'immutable_date',
            'held_since' => 'datetime',
            'info_requested_on' => 'immutable_date',
            'info_reminded_on' => 'immutable_date',
            'info_answered_on' => 'immutable_date',
            'followed_up_on' => 'immutable_date',
            'escalated_manager_on' => 'immutable_date',
            'public_protector_draft_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsToMany<Finding, $this> */
    public function findings(): BelongsToMany
    {
        return $this->belongsToMany(Finding::class)->orderBy('number');
    }

    /** @return HasMany<DisputeEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DisputeEvent::class)->orderBy('happened_on')->orderBy('id');
    }

    /** @return HasMany<DisputeMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->orderBy('id');
    }

    /** The address the municipality replies to: d-<token>@<inbound domain>. */
    public function replyAddress(): string
    {
        return 'd-'.$this->reply_token.'@'.config('auditor.inbound_email_domain');
    }

    /** The municipality asked the customer for something and is still waiting. */
    public function waitingOnCustomer(): bool
    {
        return $this->info_requested_on !== null && $this->info_answered_on === null;
    }

    /** Emailed by us, so we read the replies and chase them. */
    public function sentByEmail(): bool
    {
        return $this->sent_to !== null;
    }

    public function facts(): CaseFacts
    {
        $bill = $this->bill;

        return new CaseFacts(
            billDate: $bill?->bill_date,
            checkedOn: $bill?->checked_at?->toImmutable()->startOfDay(),
            findingsSummary: $this->findingsSummary(),
            lodgedOn: $this->lodged_on,
            acknowledgedOn: $this->acknowledged_on,
            reference: (string) $this->reference,
            replyReceivedOn: $this->reply_received_on,
            escalatedCouncillorOn: $this->escalated_councillor_on,
            escalatedPublicProtectorOn: $this->escalated_public_protector_on,
            tariffFixedOn: $this->tariff_fixed_on,
            monthlySaving: Money::ofNullable($this->monthly_saving),
            creditOn: $this->credit_on,
            creditAmount: Money::ofNullable($this->credit_amount),
            closedOn: $this->closed_on,
            managed: $this->mode->isManagedByUs(),
        );
    }

    public function stage(?\DateTimeInterface $today = null): Stage
    {
        return CaseClock::stage($this->facts(), $this->property->rules(), $today ?? now());
    }

    public function status(?\DateTimeInterface $today = null): CaseStatus
    {
        return CaseClock::status($this->facts(), $this->property->rules(), $today ?? now());
    }

    public function findingsSummary(): string
    {
        $count = $this->findings->count();

        if ($count === 0) {
            return '';
        }

        $noun = $count === 1 ? 'issue' : 'issues';

        return "{$count} {$noun}, ".Money::format($this->disputed_total).' disputed';
    }
}
