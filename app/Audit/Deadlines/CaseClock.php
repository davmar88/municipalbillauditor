<?php

namespace App\Audit\Deadlines;

use App\Audit\Dates;
use App\Audit\Money;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Deadlines, escalation dates and the timeline for one dispute.
 */
final class CaseClock
{
    public static function disputeDeadline(DateTimeInterface $billDate, MetroRules $rules): DateTimeImmutable
    {
        return Dates::addDays($billDate, $rules->disputeWindowDays);
    }

    public static function replyDue(DateTimeInterface $lodgedOn, MetroRules $rules): DateTimeImmutable
    {
        return Dates::addDays($lodgedOn, $rules->replyWindowDays);
    }

    public static function councillorEscalationOn(DateTimeInterface $lodgedOn, MetroRules $rules): DateTimeImmutable
    {
        return Dates::addDays(self::replyDue($lodgedOn, $rules), 1);
    }

    public static function publicProtectorEscalationOn(DateTimeInterface $escalatedOn, MetroRules $rules): DateTimeImmutable
    {
        return Dates::addDays($escalatedOn, $rules->councillorWindowDays);
    }

    public static function stage(CaseFacts $facts, MetroRules $rules, DateTimeInterface $today): Stage
    {
        return match (true) {
            $facts->creditOn !== null => Stage::Resolved,
            $facts->closedOn !== null => Stage::Closed,
            $facts->lodgedOn === null => Stage::Draft,
            $facts->escalatedPublicProtectorOn !== null => Stage::WithPublicProtector,
            $facts->escalatedCouncillorOn !== null => Dates::daysBetween(self::publicProtectorEscalationOn($facts->escalatedCouncillorOn, $rules), $today) > 0
                ? Stage::CouncillorOverdue
                : Stage::WithCouncillor,
            // A corrected tariff is the municipality acting on the dispute.
            $facts->replyReceivedOn !== null || $facts->tariffFixedOn !== null => Stage::AwaitingCredit,
            Dates::daysBetween(self::replyDue($facts->lodgedOn, $rules), $today) > 0 => Stage::ReplyOverdue,
            default => Stage::AwaitingReply,
        };
    }

    public static function status(CaseFacts $facts, MetroRules $rules, DateTimeInterface $today): CaseStatus
    {
        $stage = self::stage($facts, $rules, $today);

        switch ($stage) {
            case Stage::Resolved:
                $amount = $facts->creditAmount !== null ? ' of '.Money::format($facts->creditAmount) : '';

                return new CaseStatus($stage, 'Credit received', note: "A credit{$amount} appeared on ".Dates::short($facts->creditOn).'.');

            case Stage::Closed:
                return new CaseStatus($stage, 'Closed');

            case Stage::Draft:
                if ($facts->billDate === null) {
                    return new CaseStatus($stage, 'Ready to lodge');
                }

                $deadline = self::disputeDeadline($facts->billDate, $rules);
                $left = Dates::daysBetween($today, $deadline);

                if ($left < 0) {
                    return new CaseStatus(
                        $stage,
                        'Lodge as soon as you can',
                        nextDate: $deadline,
                        daysLeft: $left,
                        note: 'The usual dispute window closed on '.Dates::short($deadline).'. A late dispute can still be lodged.',
                    );
                }

                return new CaseStatus(
                    $stage,
                    'Ready to lodge',
                    nextDate: $deadline,
                    countdownLabel: 'until the dispute window closes on '.Dates::short($deadline),
                    daysLeft: $left,
                );

            case Stage::AwaitingReply:
                $due = self::replyDue($facts->lodgedOn, $rules);
                $escalateOn = Dates::short(self::councillorEscalationOn($facts->lodgedOn, $rules));
                $elapsed = Dates::daysBetween($facts->lodgedOn, $today);

                return new CaseStatus(
                    $stage,
                    'Waiting for the municipality',
                    nextDate: $due,
                    countdownLabel: 'until a reply is due on '.Dates::short($due),
                    daysLeft: Dates::daysBetween($today, $due),
                    progress: max(0.0, min(1.0, $elapsed / max(1, $rules->replyWindowDays))),
                    note: $facts->managed
                        ? "No reply by then? We escalate to your ward councillor on {$escalateOn}. You don't need to do anything."
                        : "No reply by then? We'll remind you to escalate to your ward councillor on {$escalateOn}.",
                );

            case Stage::ReplyOverdue:
                $due = self::replyDue($facts->lodgedOn, $rules);
                $action = $facts->managed ? "We're escalating it" : 'Escalate it';

                return new CaseStatus(
                    $stage,
                    'No reply in time',
                    nextDate: $due,
                    daysLeft: Dates::daysBetween($today, $due),
                    note: 'The reply was due on '.Dates::short($due).". {$action} to your ward councillor.",
                );

            case Stage::AwaitingCredit:
                return new CaseStatus(
                    $stage,
                    'Waiting for the credit',
                    note: $facts->replyReceivedOn === null
                        ? 'The tariff has been corrected. We read every new bill until the credit for past months appears.'
                        : 'The municipality has replied. We read every new bill until the credit appears.',
                );

            case Stage::WithCouncillor:
                $next = self::publicProtectorEscalationOn($facts->escalatedCouncillorOn, $rules);
                $who = $facts->managed ? 'We' : 'You';

                return new CaseStatus(
                    $stage,
                    'With your ward councillor',
                    nextDate: $next,
                    countdownLabel: 'until the Public Protector step on '.Dates::short($next),
                    daysLeft: Dates::daysBetween($today, $next),
                    note: "{$who} can take it to the Public Protector if the councillor can't resolve it.",
                );

            case Stage::CouncillorOverdue:
                return new CaseStatus(
                    $stage,
                    'Ready for the Public Protector',
                    note: "The councillor hasn't resolved it in time. The next step is the Public Protector.",
                );

            default:
                return new CaseStatus($stage, 'With the Public Protector', note: "We'll keep reading your bills for the credit.");
        }
    }

    /**
     * @return list<TimelineStep>
     */
    public static function timeline(CaseFacts $facts, MetroRules $rules, DateTimeInterface $today): array
    {
        $stage = self::stage($facts, $rules, $today);
        $finished = in_array($stage, [Stage::Resolved, Stage::Closed], true);
        $steps = [new TimelineStep('checked', 'Bill checked', $facts->findingsSummary, $facts->checkedOn, 'done')];

        if ($facts->lodgedOn !== null) {
            $steps[] = new TimelineStep('lodged', 'Dispute lodged', "Sent to the municipality's billing queries", $facts->lodgedOn, 'done');
        } else {
            $detail = $facts->billDate !== null ? 'Lodge by '.Dates::short(self::disputeDeadline($facts->billDate, $rules)) : '';
            $steps[] = new TimelineStep('lodged', 'Lodge the dispute', $detail, null, 'current');
        }

        if ($facts->acknowledgedOn !== null) {
            $detail = $facts->reference !== '' ? "Reference {$facts->reference}" : 'Reference number received';
            $steps[] = new TimelineStep('acknowledged', 'Acknowledged', $detail, $facts->acknowledgedOn, 'done');
        } elseif ($facts->lodgedOn !== null && ! $finished) {
            $steps[] = new TimelineStep('acknowledged', 'Acknowledged', 'Add the reference number when it arrives', null, 'upcoming');
        }

        if ($facts->replyReceivedOn !== null) {
            $steps[] = new TimelineStep('reply', 'Reply received', '', $facts->replyReceivedOn, 'done');
        } elseif ($facts->lodgedOn !== null && ! $finished && $facts->tariffFixedOn === null) {
            $due = self::replyDue($facts->lodgedOn, $rules);

            if ($stage === Stage::AwaitingReply) {
                $steps[] = new TimelineStep('reply', 'Waiting for a reply', 'Due '.Dates::short($due), $due, 'current');
            } elseif ($stage === Stage::ReplyOverdue) {
                // The wait is over; escalating is the step that's live now.
                $steps[] = new TimelineStep('reply', 'No reply in time', 'Was due '.Dates::short($due), $due, 'done');
            } else {
                $steps[] = new TimelineStep('reply', 'No reply in time', 'Was due '.Dates::short($due), $due, 'done');
            }
        }

        if ($facts->escalatedCouncillorOn !== null) {
            $steps[] = new TimelineStep('councillor', 'Escalated to ward councillor', '', $facts->escalatedCouncillorOn, 'done');
        } elseif (! $finished && $stage !== Stage::AwaitingCredit) {
            $when = $facts->lodgedOn !== null ? self::councillorEscalationOn($facts->lodgedOn, $rules) : null;
            $detail = match (true) {
                $stage === Stage::ReplyOverdue && $facts->managed => 'There was no reply, so our team writes to your councillor',
                $stage === Stage::ReplyOverdue => 'There was no reply, so this is the next step. The letter is ready.',
                $when !== null => Dates::short($when).", only if there's no reply",
                default => "Only if there's no reply",
            };
            $steps[] = new TimelineStep('councillor', 'Escalate to ward councillor', $detail, $when, $stage === Stage::ReplyOverdue ? 'current' : 'if_needed');
        }

        if ($facts->escalatedPublicProtectorOn !== null) {
            $steps[] = new TimelineStep('public_protector', 'Escalated to the Public Protector', '', $facts->escalatedPublicProtectorOn, 'done');
        } elseif (! $finished && $stage !== Stage::AwaitingCredit) {
            $state = $stage === Stage::CouncillorOverdue ? 'current' : 'if_needed';
            $steps[] = new TimelineStep('public_protector', 'Escalate to the Public Protector', "If the councillor can't resolve it", null, $state);
        }

        if ($facts->tariffFixedOn !== null) {
            $saving = $facts->monthlySaving !== null ? 'Saving of '.Money::format($facts->monthlySaving).' a month confirmed' : '';
            $steps[] = new TimelineStep('tariff_fixed', 'Tariff corrected', $saving, $facts->tariffFixedOn, 'done');
        }

        if ($facts->creditOn !== null) {
            $detail = $facts->creditAmount !== null ? Money::format($facts->creditAmount).' credited' : '';
            $steps[] = new TimelineStep('credit', 'Credit on your account', $detail, $facts->creditOn, 'done');
        } elseif ($stage !== Stage::Closed) {
            $state = $stage === Stage::AwaitingCredit ? 'current' : 'upcoming';
            $steps[] = new TimelineStep('credit', 'Credit on your account', 'We look for it on every new bill', null, $state);
        }

        return $steps;
    }
}
