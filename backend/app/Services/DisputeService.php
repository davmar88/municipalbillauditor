<?php

namespace App\Services;

use App\Audit\AuditService;
use App\Audit\OverchargeTotals;
use App\Enums\DisputeChannel;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\FindingStatus;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dispute lifecycle: draft -> submitted -> acknowledged -> escalated ->
 * resolved / rejected. Invalid transitions raise a 422.
 */
class DisputeService
{
    public function __construct(
        private readonly DisputeLetterBuilder $letters,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  list<int>  $findingIds
     */
    public function create(Bill $bill, User $user, array $findingIds): Dispute
    {
        return DB::transaction(function () use ($bill, $user, $findingIds) {
            $ids = array_values(array_unique(array_map('intval', $findingIds)));
            sort($ids);

            $findings = Finding::query()
                ->where('bill_id', $bill->id)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($findings->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'finding_ids' => ['Choose findings from this bill.'],
                ]);
            }
            if ($findings->contains(fn (Finding $f) => $f->status !== FindingStatus::Open)) {
                throw ValidationException::withMessages([
                    'finding_ids' => ['Only open findings can be disputed. Some of these are dismissed or already in a dispute.'],
                ]);
            }

            $ordered = $findings->sortBy(fn (Finding $f) => [-$f->severity->rank(), $f->id])->values();
            $letter = $this->letters->build($bill, $ordered, $user);

            $dispute = new Dispute([
                'status' => DisputeStatus::Draft,
                'finding_ids' => $ids,
                'amount_disputed_cents' => OverchargeTotals::sum($findings),
                'letter_subject' => $letter['subject'],
                'letter_body' => $letter['body'],
                'escalation_level' => 0,
            ]);
            $dispute->bill()->associate($bill);
            $dispute->save();

            Finding::query()->whereIn('id', $ids)->update(['status' => FindingStatus::Disputed->value]);

            $this->record($dispute, DisputeEventType::Created);

            return $dispute;
        });
    }

    /**
     * @param  array{letter_subject?: string, letter_body?: string}  $data
     */
    public function updateLetter(Dispute $dispute, array $data): Dispute
    {
        $this->ensureStatus($dispute, [DisputeStatus::Draft], 'You can only edit the letter while the dispute is a draft.');

        return DB::transaction(function () use ($dispute, $data) {
            $dispute->fill(array_intersect_key($data, array_flip(['letter_subject', 'letter_body'])));
            $dispute->save();
            $this->record($dispute, DisputeEventType::LetterEdited);

            return $dispute;
        });
    }

    public function delete(Dispute $dispute): void
    {
        $this->ensureStatus($dispute, [DisputeStatus::Draft], 'Only draft disputes can be deleted.');

        DB::transaction(function () use ($dispute) {
            Finding::query()
                ->where('bill_id', $dispute->bill_id)
                ->whereIn('id', $dispute->finding_ids)
                ->where('status', FindingStatus::Disputed->value)
                ->update(['status' => FindingStatus::Open->value]);

            $dispute->delete();
        });

        // A disputed finding is kept even after its rule stops firing. Once
        // it is open again, re-auditing removes it if it no longer applies.
        $this->audit->audit($dispute->bill);
    }

    /**
     * @param  array{channel: string, municipality_reference?: string|null, submitted_at?: string|null}  $data
     */
    public function submit(Dispute $dispute, array $data): Dispute
    {
        $this->ensureStatus($dispute, [DisputeStatus::Draft], 'This dispute has already been submitted.');

        return DB::transaction(function () use ($dispute, $data) {
            $submittedAt = $this->timestamp($data['submitted_at'] ?? null);

            $dispute->status = DisputeStatus::Submitted;
            $dispute->channel = DisputeChannel::from($data['channel']);
            $dispute->municipality_reference = $data['municipality_reference'] ?? null;
            $dispute->submitted_at = $submittedAt;
            $dispute->response_due_at = $submittedAt->addDays($this->responseWaitDays($dispute));
            $dispute->save();

            $this->record($dispute, DisputeEventType::Submitted, null, $submittedAt);

            return $dispute;
        });
    }

    /**
     * @param  array{type: string, note?: string|null, occurred_at?: string|null}  $data
     */
    public function addEvent(Dispute $dispute, array $data): Dispute
    {
        $type = DisputeEventType::from($data['type']);

        if ($type !== DisputeEventType::Note) {
            $this->ensureStatus($dispute, DisputeStatus::active(), match ($dispute->status) {
                DisputeStatus::Draft => 'Submit the dispute to the municipality first.',
                default => 'This dispute is already closed.',
            });
        }

        return DB::transaction(function () use ($dispute, $data, $type) {
            if ($type === DisputeEventType::Acknowledged && $dispute->status === DisputeStatus::Submitted) {
                $dispute->status = DisputeStatus::Acknowledged;
                $dispute->save();
            }

            $this->record($dispute, $type, $data['note'] ?? null, $this->timestamp($data['occurred_at'] ?? null));

            return $dispute;
        });
    }

    public function escalate(Dispute $dispute, ?string $note): Dispute
    {
        $this->ensureStatus($dispute, DisputeStatus::active(), match (true) {
            $dispute->status === DisputeStatus::Draft => 'Submit the dispute to the municipality before escalating it.',
            default => 'This dispute is already closed.',
        });

        if (! $dispute->hasNextStep()) {
            throw ValidationException::withMessages([
                'status' => ['There is no further escalation step for this dispute.'],
            ]);
        }

        return DB::transaction(function () use ($dispute, $note) {
            $now = CarbonImmutable::now('UTC');

            $dispute->escalation_level += 1;
            $dispute->status = DisputeStatus::Escalated;
            $dispute->escalated_at = $now;
            $dispute->response_due_at = $now->addDays($this->responseWaitDays($dispute));
            $dispute->save();

            $this->record($dispute, DisputeEventType::Escalated, $note, $now);

            return $dispute;
        });
    }

    /**
     * @param  array{outcome: string, outcome_amount_cents?: int|null, note?: string|null}  $data
     */
    public function resolve(Dispute $dispute, array $data): Dispute
    {
        $this->ensureStatus($dispute, DisputeStatus::active(), match (true) {
            $dispute->status === DisputeStatus::Draft => 'Submit the dispute to the municipality first.',
            default => 'This dispute is already closed.',
        });

        return DB::transaction(function () use ($dispute, $data) {
            $now = CarbonImmutable::now('UTC');
            $outcome = DisputeStatus::from($data['outcome']);

            $dispute->status = $outcome;
            $dispute->outcome_amount_cents = isset($data['outcome_amount_cents']) ? (int) $data['outcome_amount_cents'] : null;
            $dispute->resolved_at = $now;
            $dispute->save();

            $this->record(
                $dispute,
                $outcome === DisputeStatus::Resolved ? DisputeEventType::Resolved : DisputeEventType::Rejected,
                $data['note'] ?? null,
                $now,
            );

            return $dispute;
        });
    }

    /**
     * @param  list<DisputeStatus>  $allowed
     */
    private function ensureStatus(Dispute $dispute, array $allowed, string $message): void
    {
        if (! in_array($dispute->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function record(Dispute $dispute, DisputeEventType $type, ?string $note = null, ?CarbonImmutable $at = null): void
    {
        $dispute->events()->create([
            'type' => $type,
            'note' => $note,
            'occurred_at' => $at ?? CarbonImmutable::now('UTC'),
        ]);
        $dispute->unsetRelation('events');
    }

    private function responseWaitDays(Dispute $dispute): int
    {
        return (int) $dispute->bill->property->metroConfig()['response_wait_days'];
    }

    /**
     * Parses a client timestamp and stores it in UTC.
     */
    private function timestamp(?string $value): CarbonImmutable
    {
        return $value === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($value)->utc();
    }
}
