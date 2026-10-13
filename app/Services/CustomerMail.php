<?php

namespace App\Services;

use App\Audit\Money;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\Organisation;
use App\Notifications\CustomerNotice;
use App\Services\Billing\InsufficientCredit;
use Illuminate\Support\Facades\Notification;

/**
 * Every email we send a customer about their bills and disputes, worded in
 * one place. They go to everyone on the account with a verified address.
 */
class CustomerMail
{
    public function billChecked(Bill $bill): void
    {
        $bill->loadMissing(['findings', 'property']);
        $open = $bill->findings->filter(fn (Finding $f) => $f->isOpen());
        $questions = $open->filter(fn (Finding $f) => $f->awaitingAnswer());
        $where = $bill->property ? " for {$bill->property->label()}" : '';

        if ($questions->isNotEmpty()) {
            $this->send($bill->organisation, new CustomerNotice(
                kind: 'needs_answer',
                subject: "We need your answer on your {$bill->monthLabel()} bill",
                lines: [
                    "We checked your {$bill->monthLabel()} bill{$where}. ".($questions->count() === 1
                        ? 'One thing needs your answer before it can go in a dispute:'
                        : "{$questions->count()} things need your answers before they can go in a dispute:"),
                    ...$questions->map(fn (Finding $f) => '- '.$f->question)->values()->all(),
                ],
                actionText: 'Answer on the bill',
                actionUrl: route('bills.show', $bill),
            ));

            return;
        }

        $total = Money::sum($open->filter(fn (Finding $f) => $f->amount !== null)->map(fn (Finding $f) => Money::of($f->amount)));

        $this->send($bill->organisation, new CustomerNotice(
            kind: 'bill_checked',
            subject: $open->isEmpty() ? "Your {$bill->monthLabel()} bill looks right" : "We found a problem on your {$bill->monthLabel()} bill",
            lines: $open->isEmpty()
                ? ["We checked your {$bill->monthLabel()} bill{$where} and found nothing wrong. There's nothing for you to do."]
                : [
                    "We checked your {$bill->monthLabel()} bill{$where} and found ".($open->count() === 1 ? 'a problem' : "{$open->count()} problems")
                        .($total->isZero() ? '.' : ', worth up to '.Money::format($total).'.'),
                    'Have a look, and approve the dispute when you are happy with it.',
                ],
            actionText: 'See the bill',
            actionUrl: route('bills.show', $bill),
        ));
    }

    public function waitingForCredit(Bill $bill, InsufficientCredit $reason): void
    {
        $this->send($bill->organisation, new CustomerNotice(
            kind: 'waiting_for_credit',
            subject: 'A bill is waiting for credit',
            lines: [
                'We received a bill'.($bill->file_name ? " ({$bill->file_name})" : '').'. Checking it costs '.Money::format($reason->price)
                    .', and your credit is '.Money::format($reason->balance).'.',
                "Top up and we'll check it straight away. You don't need to send it again.",
            ],
            actionText: 'Top up',
            actionUrl: route('credit.index'),
        ));
    }

    public function disputeCreditNeeded(Dispute $dispute, InsufficientCredit $reason): void
    {
        $this->send($dispute->organisation, new CustomerNotice(
            kind: 'waiting_for_credit',
            subject: "Your {$dispute->bill->monthLabel()} dispute is ready to send",
            lines: [
                'Your dispute is ready, and you asked us to send disputes for you. Sending it costs '.Money::format($reason->price)
                    .', and your credit is '.Money::format($reason->balance).'.',
                'Top up, then approve it on the dispute page.',
            ],
            actionText: 'Top up',
            actionUrl: route('credit.index'),
        ));
    }

    public function disputeSent(Dispute $dispute, string $to): void
    {
        $name = $dispute->property->municipality?->name ?? 'the municipality';

        $this->send($dispute->organisation, new CustomerNotice(
            kind: 'dispute_sent',
            subject: "Your {$dispute->bill->monthLabel()} dispute has been sent",
            lines: [
                "We emailed your dispute to {$name} at {$to} and copied you in.",
                'We read their replies, follow up if they go quiet and escalate if we have to. We will email you when anything happens.',
            ],
            actionText: 'Track the dispute',
            actionUrl: route('disputes.show', $dispute),
        ));
    }

    public function disputeHeld(Dispute $dispute): void
    {
        $name = $dispute->property->municipality?->name ?? 'your municipality';

        $this->send($dispute->organisation, new CustomerNotice(
            kind: 'dispute_held',
            subject: "We're holding your {$dispute->bill->monthLabel()} dispute for now",
            lines: [
                "We don't have an email address for {$name}'s account queries yet, and we never guess one.",
                "Our team is finding it now. The dispute goes out as soon as they do, and we'll let you know.",
            ],
            actionText: 'Open the dispute',
            actionUrl: route('disputes.show', $dispute),
        ));
    }

    /**
     * @param  list<string>  $summary
     */
    public function municipalityReplied(Dispute $dispute, string $subject, array $summary, ?string $todo): void
    {
        $this->send($dispute->organisation, new CustomerNotice(
            kind: 'municipality_replied',
            subject: $subject,
            lines: [...$summary, $todo ?? "There's nothing you need to do."],
            actionText: 'Open the dispute',
            actionUrl: route('disputes.show', $dispute),
        ));
    }

    public function creditFound(Dispute $dispute, string $amount, Bill $on): void
    {
        $this->send($dispute->organisation, new CustomerNotice(
            kind: 'credit_found',
            subject: 'A credit of '.Money::format($amount).' is on your account',
            lines: [
                'Your '.$on->monthLabel().' bill shows a credit of '.Money::format($amount)
                    ." after your {$dispute->bill->monthLabel()} dispute.",
                "Not the refund you were expecting? Tell us on the dispute page and we'll keep looking.",
            ],
            actionText: 'See the dispute',
            actionUrl: route('disputes.show', $dispute),
        ));
    }

    /**
     * The Public Protector complaint, for the customer to lodge themselves.
     * We never send anything to the Public Protector.
     */
    public function publicProtectorDraft(Dispute $dispute, string $subject, string $body): void
    {
        $this->send($dispute->organisation, new CustomerNotice(
            kind: 'public_protector_draft',
            subject: "Your {$dispute->bill->monthLabel()} dispute is ready for the Public Protector",
            lines: [
                'The municipality has not resolved your dispute, even after we escalated it. The next step is a complaint to the Public Protector, which only you can lodge.',
                'We have written it for you below. Lodge it through the Public Protector\'s website, and attach your bill and the dispute emails we copied you in on.',
                "Subject: {$subject}",
                ...explode("\n", $body),
            ],
            actionText: 'Open the dispute',
            actionUrl: route('disputes.show', $dispute),
        ));
    }

    public function send(Organisation $organisation, CustomerNotice $notice): void
    {
        $users = $organisation->users()->whereNotNull('email_verified_at')->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, $notice);
        }
    }
}
