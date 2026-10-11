<?php

namespace App\Services;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Enums\CheckType;
use App\Audit\Fees\Pricing;
use App\Audit\Letters\Letter;
use App\Audit\Letters\LetterInput;
use App\Audit\Letters\LetterItem;
use App\Audit\Letters\LetterWriter;
use App\Audit\Money;
use App\Enums\DisputeEventKind;
use App\Enums\DisputeMode;
use App\Enums\FeeKind;
use App\Enums\FeeStatus;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\FeeInvoice;
use App\Models\Finding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafting, lodging and tracking disputes, and the fees that follow them.
 */
class DisputeService
{
    /**
     * The draft dispute for a bill: its letter is rebuilt from the bill's
     * current findings and answers each time, until the customer submits it.
     */
    public function draft(Bill $bill, User $user): Dispute
    {
        $bill->loadMissing(['property.metro', 'findings', 'organisation']);
        $findings = $bill->findings->filter(fn (Finding $f) => $f->disputable())->values();

        if ($findings->isEmpty()) {
            throw ValidationException::withMessages(['dispute' => 'There is nothing on this bill to dispute yet.']);
        }

        $dispute = $bill->disputes()->whereNull('submitted_on')->latest('id')->first() ?? new Dispute([
            'bill_id' => $bill->id,
            'property_id' => $bill->property_id,
            'mode' => $this->defaultMode($bill->organisation->kind),
        ]);

        $letter = $this->letterFor($bill, $findings, $user);
        $dispute->organisation_id = $bill->organisation_id;
        $dispute->fill([
            'letter_subject' => $letter->subject,
            'letter_body' => $letter->body,
            'disputed_total' => (string) $letter->disputedTotal,
            'undisputed_amount' => $letter->undisputedAmount !== null ? (string) $letter->undisputedAmount : null,
            'dispute_deadline' => $bill->bill_date !== null
                ? CaseClock::disputeDeadline($bill->bill_date, $bill->property->metro->rules())
                : null,
            'back_refund_claimed' => $this->backRefundClaimed($findings, $letter),
        ])->save();

        $dispute->findings()->sync($findings->pluck('id'));

        return $dispute->load('findings');
    }

    /**
     * The customer approves the letter. Self-sent disputes are lodged at once;
     * managed ones go to our team to lodge.
     */
    public function submit(Dispute $dispute, DisputeMode $mode, User $user, ?string $letterBody = null): Dispute
    {
        if ($dispute->submitted_on !== null) {
            throw ValidationException::withMessages(['dispute' => 'This dispute has already been sent.']);
        }

        $kind = $dispute->organisation->kind;
        $allowed = match ($kind) {
            OrganisationKind::Household => [DisputeMode::Self, DisputeMode::Managed],
            OrganisationKind::Agent => [DisputeMode::Self, DisputeMode::Managed],
            OrganisationKind::Business => [DisputeMode::SuccessFee],
        };

        if (! in_array($mode, $allowed, true)) {
            throw ValidationException::withMessages(['mode' => 'That way of sending isn\'t available on this account.']);
        }

        $today = CarbonImmutable::today();

        DB::transaction(function () use ($dispute, $mode, $user, $letterBody, $today, $kind) {
            $dispute->fill([
                'mode' => $mode,
                'letter_body' => filled($letterBody) ? $letterBody : $dispute->letter_body,
                'submitted_on' => $today,
            ])->save();

            if ($mode === DisputeMode::Self) {
                $this->lodge($dispute, $today, null, $user, 'Sent by you');

                return;
            }

            if ($mode === DisputeMode::Managed && $kind === OrganisationKind::Household) {
                $price = (string) config('auditor.prices.household_dispute');

                FeeInvoice::create([
                    'organisation_id' => $dispute->organisation_id,
                    'dispute_id' => $dispute->id,
                    'kind' => FeeKind::ManagedDispute,
                    'description' => 'Managed dispute for the '.$dispute->bill->monthLabel().' bill',
                    'amount' => (string) Money::money($price),
                    'status' => FeeStatus::Due,
                ]);
            }

            $dispute->events()->create([
                'user_id' => $user->id,
                'kind' => DisputeEventKind::Note,
                'happened_on' => $today,
                'note' => 'Handed to our team to lodge.',
            ]);
        });

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
                DisputeEventKind::ReplyReceived => $dispute->reply_received_on = $on,
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

            if ($dispute->mode === DisputeMode::SuccessFee) {
                $this->invoiceSuccessFee($dispute, $kind, $amount, $details['period_month'] ?? $on);
            }

            return $event;
        });
    }

    public function councillorLetter(Dispute $dispute, User $user): ?Letter
    {
        if ($dispute->lodged_on === null) {
            return null;
        }

        $property = $dispute->property;
        $metro = $property->metro;

        return LetterWriter::councillor(
            metroName: $metro->name,
            senderName: $this->senderName($dispute->organisation->kind, $user, $dispute->organisation->name),
            accountNumber: $dispute->bill->account_number ?? $property->account_number ?? '[account number]',
            propertyAddress: (string) $property->address,
            lodgedOn: $dispute->lodged_on,
            replyDueOn: CaseClock::replyDue($dispute->lodged_on, $metro->rules()),
            reference: (string) $dispute->reference,
            disputedTotal: Money::of($dispute->disputed_total),
            letterDate: CarbonImmutable::today(),
        );
    }

    public function defaultMode(OrganisationKind $kind): DisputeMode
    {
        return match ($kind) {
            OrganisationKind::Household => DisputeMode::Self,
            OrganisationKind::Agent => DisputeMode::Managed,
            OrganisationKind::Business => DisputeMode::SuccessFee,
        };
    }

    /**
     * @param  Collection<int, Finding>  $findings
     */
    private function letterFor(Bill $bill, Collection $findings, User $user): Letter
    {
        $property = $bill->property;
        $metro = $property->metro;

        return LetterWriter::dispute(new LetterInput(
            addressee: $metro->billing_addressee,
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
            extraParagraph: (string) $metro->letter_paragraph,
        ));
    }

    private function senderName(OrganisationKind $kind, User $user, string $organisationName): string
    {
        return $kind === OrganisationKind::Household ? $user->name : $organisationName;
    }

    /**
     * What a business claims back: the tariff check's estimate of past
     * overcharges where there is one, otherwise the amount disputed.
     *
     * @param  Collection<int, Finding>  $findings
     */
    private function backRefundClaimed(Collection $findings, Letter $letter): ?string
    {
        $tariff = $findings->first(fn (Finding $f) => $f->check_type === CheckType::WrongTariff && isset($f->data['back_refund_estimate']));

        return $tariff !== null ? (string) $tariff->data['back_refund_estimate'] : (string) $letter->disputedTotal;
    }

    /**
     * Success fees are only invoiced once money reaches the customer's
     * municipal account: on the back-refund credit, and on each of the first
     * 12 months of savings seen on a bill.
     */
    private function invoiceSuccessFee(Dispute $dispute, DisputeEventKind $kind, ?string $amount, CarbonImmutable $month): void
    {
        $rate = $dispute->organisation->successFeeRate();

        if ($kind === DisputeEventKind::CreditReceived && $amount !== null) {
            FeeInvoice::create([
                'organisation_id' => $dispute->organisation_id,
                'dispute_id' => $dispute->id,
                'kind' => FeeKind::BackRefund,
                'description' => 'Success fee on the back-refund credited',
                'basis' => $amount,
                'rate' => $rate,
                'amount' => (string) Pricing::feeOn($amount, $rate),
                'status' => FeeStatus::Due,
            ]);

            return;
        }

        $isSaving = $kind === DisputeEventKind::TariffFixed || $kind === DisputeEventKind::SavingSeen;

        if (! $isSaving || $dispute->monthly_saving === null) {
            return;
        }

        $periodMonth = $month->startOfMonth();
        $savings = $dispute->feeInvoices()->where('kind', FeeKind::MonthlySaving);

        if ((clone $savings)->count() >= Pricing::SAVING_MONTHS || (clone $savings)->whereDate('period_month', $periodMonth)->exists()) {
            return;
        }

        FeeInvoice::create([
            'organisation_id' => $dispute->organisation_id,
            'dispute_id' => $dispute->id,
            'kind' => FeeKind::MonthlySaving,
            'description' => 'Success fee on the '.$month->format('F Y').' saving',
            'basis' => $dispute->monthly_saving,
            'rate' => $rate,
            'amount' => (string) Pricing::feeOn($dispute->monthly_saving, $rate),
            'status' => FeeStatus::Due,
            'period_month' => $periodMonth,
        ]);
    }
}
