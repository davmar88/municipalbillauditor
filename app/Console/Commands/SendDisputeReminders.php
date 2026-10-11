<?php

namespace App\Console\Commands;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Deadlines\Stage;
use App\Audit\Money;
use App\Enums\BillStatus;
use App\Enums\DisputeMode;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\Reminder;
use App\Notifications\DisputeReminder;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Runs daily. Reminds customers before the dispute window closes on a
 * flagged bill, and when a dispute they sent themselves needs escalating.
 * Managed disputes are escalated by our team from the staff queue instead.
 */
#[Signature('disputes:remind')]
#[Description('Email customers about dispute deadlines and overdue replies')]
class SendDisputeReminders extends Command
{
    /** Days before the dispute window closes that we remind, nearest first. */
    private const WINDOW_NOTICES = [2, 7];

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $sent = $this->windowClosing($today) + $this->escalations($today);

        $this->info($sent === 1 ? '1 reminder sent.' : "{$sent} reminders sent.");

        return self::SUCCESS;
    }

    private function windowClosing(CarbonImmutable $today): int
    {
        $sent = 0;

        $bills = Bill::query()
            ->where('status', BillStatus::Checked)
            ->whereNotNull('bill_date')
            ->where('bill_date', '>=', $today->subYear())
            ->whereNotNull('property_id')
            ->whereDoesntHave('disputes', fn (Builder $q) => $q->whereNotNull('submitted_on'))
            ->whereHas('findings', fn (Builder $q) => $q->where('state', '!=', 'dismissed'))
            ->with(['property.metro', 'findings', 'organisation.users'])
            ->get();

        foreach ($bills as $bill) {
            $open = $bill->findings->filter(fn (Finding $f) => $f->isOpen());

            if ($open->isEmpty()) {
                continue;
            }

            $deadline = CaseClock::disputeDeadline($bill->bill_date, $bill->property->metro->rules());
            $left = (int) $today->diffInDays($deadline, false);
            $notice = collect(self::WINDOW_NOTICES)->first(fn (int $days) => $left >= 0 && $left <= $days);

            if ($notice === null) {
                continue;
            }

            $amount = Money::sum($open->filter(fn (Finding $f) => $f->amount !== null)->map(fn (Finding $f) => Money::of($f->amount)));
            $sent += $this->send($bill, "window_{$notice}", $deadline, new DisputeReminder(
                kind: 'window_closing',
                subject: "Dispute your {$bill->monthLabel()} bill by {$deadline->format('j M')}",
                lines: [
                    "Your {$bill->monthLabel()} bill for {$bill->property->label()} has ".($open->count() === 1 ? 'a problem' : "{$open->count()} problems")
                        .($amount->isZero() ? '' : ', worth up to '.Money::format($amount)).'.',
                    $left === 0
                        ? 'The usual dispute window closes today.'
                        : "The usual dispute window closes on {$deadline->format('j F')}, in {$left} ".($left === 1 ? 'day' : 'days').'.',
                ],
                actionText: 'See the bill',
                actionUrl: route('bills.show', $bill),
            ));
        }

        return $sent;
    }

    private function escalations(CarbonImmutable $today): int
    {
        $sent = 0;

        $disputes = Dispute::query()
            ->where('mode', DisputeMode::Self)
            ->whereNotNull('lodged_on')
            ->whereNull('credit_on')
            ->whereNull('closed_on')
            ->with(['property.metro', 'bill', 'organisation.users'])
            ->get();

        foreach ($disputes as $dispute) {
            $stage = $dispute->stage($today);
            $rules = $dispute->property->metro->rules();

            $reminder = match ($stage) {
                Stage::ReplyOverdue => [
                    'escalate_councillor',
                    CaseClock::replyDue($dispute->lodged_on, $rules),
                    "No reply about your {$dispute->bill->monthLabel()} bill: time to go to your ward councillor",
                    "The municipality's reply was due on ".CaseClock::replyDue($dispute->lodged_on, $rules)->format('j F').'. The next step is your ward councillor. We have written the letter for you.',
                ],
                Stage::CouncillorOverdue => [
                    'escalate_public_protector',
                    CaseClock::publicProtectorEscalationOn($dispute->escalated_councillor_on, $rules),
                    "Your {$dispute->bill->monthLabel()} dispute is ready for the Public Protector",
                    "The councillor hasn't resolved it in time. You can now take it to the Public Protector.",
                ],
                default => null,
            };

            if ($reminder === null) {
                continue;
            }

            [$kind, $dueOn, $subject, $line] = $reminder;

            $sent += $this->send($dispute, $kind, $dueOn, new DisputeReminder(
                kind: $kind,
                subject: $subject,
                lines: [$line, 'Got a reply after all? Record it on the dispute and we will stop reminding you.'],
                actionText: 'Open the dispute',
                actionUrl: route('disputes.show', $dispute),
            ));
        }

        return $sent;
    }

    /**
     * Sends a reminder once: a second run on the same day, or the next day,
     * finds it already recorded.
     */
    private function send(Bill|Dispute $subject, string $kind, DateTimeInterface $dueOn, DisputeReminder $notification): int
    {
        $reminder = Reminder::query()->firstOrCreate([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'kind' => $kind,
            'due_on' => CarbonImmutable::instance($dueOn)->toDateString(),
        ], [
            'organisation_id' => $subject->organisation_id,
            'sent_at' => now(),
        ]);

        if (! $reminder->wasRecentlyCreated) {
            return 0;
        }

        $users = $subject->organisation->users->whereNotNull('email_verified_at');
        Notification::send($users, $notification);

        return 1;
    }
}
