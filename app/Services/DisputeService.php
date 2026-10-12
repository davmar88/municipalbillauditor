<?php

namespace App\Services;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Letters\Letter;
use App\Audit\Letters\LetterInput;
use App\Audit\Letters\LetterItem;
use App\Audit\Letters\LetterWriter;
use App\Audit\Money;
use App\Enums\DisputeEventKind;
use App\Enums\DisputeMode;
use App\Enums\Job;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\Finding;
use App\Models\User;
use App\Services\Billing\InsufficientCredit;
use App\Services\Billing\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafting, lodging and tracking disputes.
 */
class DisputeService
{
    public function __construct(private readonly Wallet $wallet) {}

    /**
     * The draft dispute for a bill: its letter is rebuilt from the bill's
     * current findings and answers each time, until the customer submits it.
     */
    public function draft(Bill $bill, User $user): Dispute
    {
        $bill->loadMissing(['property.municipality', 'findings', 'organisation']);
        $findings = $bill->findings->filter(fn (Finding $f) => $f->disputable())->values();

        if ($findings->isEmpty()) {
            throw ValidationException::withMessages(['dispute' => 'There is nothing on this bill to dispute yet.']);
        }

        $dispute = $bill->disputes()->whereNull('submitted_on')->latest('id')->first() ?? new Dispute([
            'bill_id' => $bill->id,
            'property_id' => $bill->property_id,
            'mode' => DisputeMode::Managed,
        ]);

        $letter = $this->letterFor($bill, $findings, $user);
        $dispute->organisation_id = $bill->organisation_id;
        $dispute->fill([
            'letter_subject' => $letter->subject,
            'letter_body' => $letter->body,
            'disputed_total' => (string) $letter->disputedTotal,
            'undisputed_amount' => $letter->undisputedAmount !== null ? (string) $letter->undisputedAmount : null,
            'dispute_deadline' => $bill->bill_date !== null
                ? CaseClock::disputeDeadline($bill->bill_date, $bill->property->rules())
                : null,
        ])->save();

        $dispute->findings()->sync($findings->pluck('id'));

        return $dispute->load('findings');
    }

    /**
     * The customer approves the letter, and pays for the dispute: R49 from a
     * household's credit, included in a monthly plan. DisputeApproval then
     * emails it.
     */
    public function submit(Dispute $dispute, User $user, ?string $letterBody = null): Dispute
    {
        if ($dispute->submitted_on !== null) {
            throw ValidationException::withMessages(['dispute' => 'This dispute has already been sent.']);
        }

        $today = CarbonImmutable::today();

        try {
            DB::transaction(function () use ($dispute, $user, $letterBody, $today) {
                $this->wallet->charge($dispute->organisation, Job::Dispute, $dispute, $user);

                $dispute->fill([
                    'mode' => DisputeMode::Managed,
                    'letter_body' => filled($letterBody) ? $letterBody : $dispute->letter_body,
                    'submitted_on' => $today,
                ])->save();

                $dispute->events()->create([
                    'user_id' => $user->id,
                    'kind' => DisputeEventKind::Note,
                    'happened_on' => $today,
                    'note' => "Approved by {$user->name}.",
                ]);
            });
        } catch (InsufficientCredit $e) {
            throw ValidationException::withMessages(['credit' => $e->getMessage()]);
        }

        return $dispute->refresh();
    }

    public function lodge(Dispute $dispute, CarbonImmutable $on, ?string $reference, ?User $by, ?string $note = null): void
    {
        $dispute->fill([
            'lodged_on' => $on,
            'submitted_on' => $dispute->submitted_on ?? $on,
            'reference' => $reference ?: $dispute->reference,
        ])->save();

        $dispute->events()->create([
            'user_id' => $by?->id,
            'kind' => DisputeEventKind::Lodged,
            'happened_on' => $on,
            'note' => $note,
        ]);
    }

    /**
     * Record something that happened on a dispute and update it to match.
     *
     * @param  array{reference?: ?string, amount?: ?string, note?: ?string, credit_bill_id?: ?int, period_month?: ?CarbonImmutable}  $details
     */
    public function record(Dispute $dispute, DisputeEventKind $kind, CarbonImmutable $on, array $details = [], ?User $user = null): DisputeEvent
    {
        if ($kind !== DisputeEventKind::Note && $kind !== DisputeEventKind::Closed && $dispute->lodged_on === null) {
            throw ValidationException::withMessages(['kind' => 'Lodge the dispute first.']);
        }

        return DB::transaction(function () use ($dispute, $kind, $on, $details, $user) {
            $amount = isset($details['amount']) && $details['amount'] !== '' ? (string) Money::money($details['amount']) : null;

            match ($kind) {
                DisputeEventKind::Lodged => $dispute->lodged_on = $on,
                DisputeEventKind::Acknowledged => $dispute->fill([
                    'acknowledged_on' => $on,
                    'reference' => $details['reference'] ?? $dispute->reference,
                ]),
                // An answer either way: the municipality has dealt with it.
                DisputeEventKind::ReplyReceived, DisputeEventKind::Upheld, DisputeEventKind::Rejected => $dispute->reply_received_on ??= $on,
                // A request for information is a sign of life: no follow-up
                // needed, and no escalation while the customer owes an answer.
                DisputeEventKind::InfoRequested => $dispute->fill([
                    'acknowledged_on' => $dispute->acknowledged_on ?? $on,
                    'info_requested_on' => $on,
                    'info_reminded_on' => null,
                    'info_answered_on' => null,
                ]),
                DisputeEventKind::FollowedUp => $dispute->followed_up_on = $on,
                DisputeEventKind::EscalatedManager => $dispute->escalated_manager_on = $on,
                DisputeEventKind::EscalatedCouncillor => $dispute->escalated_councillor_on = $on,
                DisputeEventKind::EscalatedPublicProtector => $dispute->escalated_public_protector_on = $on,
                DisputeEventKind::TariffFixed => $dispute->fill([
                    'tariff_fixed_on' => $on,
                    'monthly_saving' => $amount ?? $dispute->monthly_saving,
                ]),
                DisputeEventKind::CreditReceived => $dispute->fill([
                    'credit_on' => $on,
                    'credit_amount' => $amount,
                    'credit_bill_id' => $details['credit_bill_id'] ?? null,
                ]),
                DisputeEventKind::Closed => $dispute->fill(['closed_on' => $on, 'close_reason' => $details['note'] ?? null]),
                DisputeEventKind::SavingSeen, DisputeEventKind::Note => null,
            };

            $dispute->save();

            $event = $dispute->events()->create([
                'user_id' => $user?->id,
                'kind' => $kind,
                'happened_on' => $on,
                'note' => $details['note'] ?? null,
            ]);

            // A dispute closed before it ever reached the municipality is
            // given back.
            if ($kind === DisputeEventKind::Closed && $dispute->lodged_on === null) {
                $this->wallet->refund($dispute->organisation, Job::Dispute, $dispute, 'Dispute refunded: closed before it was sent');
            }

            return $event;
        });
    }

    /**
     * The customer has sent the municipality what it asked for. The
     * escalation clock starts again from today.
     */
    public function informationSent(Dispute $dispute, ?User $user, string $note): void
    {
        if (! $dispute->waitingOnCustomer()) {
            return;
        }

        $today = CarbonImmutable::today();
        $dispute->update(['info_answered_on' => $today]);
        $this->record($dispute, DisputeEventKind::Note, $today, ['note' => $note], $user);
    }

    public function councillorLetter(Dispute $dispute, User $user): ?Letter
    {
        if ($dispute->lodged_on === null) {
            return null;
        }

        $property = $dispute->property;
        $municipality = $property->municipality;

        return LetterWriter::councillor(
            municipalityName: $municipality?->name ?? 'the municipality',
            senderName: $this->senderName($dispute->organisation->kind, $user, $dispute->organisation->name),
            accountNumber: $dispute->bill->account_number ?? $property->account_number ?? '[account number]',
            propertyAddress: (string) $property->address,
            lodgedOn: $dispute->lodged_on,
            replyDueOn: CaseClock::replyDue($dispute->lodged_on, $property->rules()),
            reference: (string) $dispute->reference,
            disputedTotal: Money::of($dispute->disputed_total),
            letterDate: CarbonImmutable::today(),
        );
    }

    /**
     * @param  Collection<int, Finding>  $findings
     */
    private function letterFor(Bill $bill, Collection $findings, User $user): Letter
    {
        $property = $bill->property;
        $municipality = $property->municipality;

        return LetterWriter::dispute(new LetterInput(
            addressee: $municipality?->billing_addressee ?? 'Account queries',
            senderName: $this->senderName($bill->organisation->kind, $user, $bill->organisation->name),
            accountNumber: $bill->account_number ?? $property->account_number ?? '[account number]',
            propertyAddress: (string) $property->address,
            billDate: $bill->bill_date,
            billMonth: $bill->month(),
            totalDue: Money::ofNullable($bill->total_due),
            items: $findings->map(fn (Finding $f) => new LetterItem(
                check: $f->check_type,
                service: $f->service,
                amount: Money::ofNullable($f->amount),
                data: $f->data ?? [],
                answer: (string) $f->answer,
            ))->all(),
            letterDate: CarbonImmutable::today(),
            propertyType: $property->property_type,
            hasMeterPhoto: $bill->meter_photo_path !== null,
            extraParagraph: (string) $municipality?->letter_paragraph,
        ));
    }

    private function senderName(OrganisationKind $kind, User $user, string $organisationName): string
    {
        return $kind === OrganisationKind::Household ? $user->name : $organisationName;
    }
}
