<?php

namespace App\Services\Disputes;

use App\Audit\Dates;
use App\Audit\Deadlines\CaseClock;
use App\Audit\Letters\LetterWriter;
use App\Audit\Money;
use App\Enums\DisputeEventKind;
use App\Enums\OrganisationKind;
use App\Models\Dispute;
use App\Notifications\CustomerNotice;
use App\Services\CustomerMail;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Chases the disputes we emailed, one step a day at most:
 *
 * 1. No acknowledgement by the reply date: a follow-up on the same thread.
 * 2. Still no answer when the councillor's window ends: an escalation to
 *    the municipal manager, when the directory has their address.
 * 3. Still nothing after that (or straight away, without a manager's
 *    address): the Public Protector complaint, drafted and emailed to the
 *    customer to lodge themselves. We never write to the Public Protector.
 *
 * An acknowledgement stops the follow-up but not the escalation; only an
 * answer (upheld, rejected, a reply, a credit) or closing it does.
 *
 * While the municipality waits on something it asked the customer for, we
 * never escalate: we remind the customer once instead. When they send it,
 * the escalation clock starts again from that day.
 */
class DisputeChaser
{
    public function __construct(
        private readonly DisputeMailer $mailer,
        private readonly DisputeService $disputes,
        private readonly CustomerMail $mail,
    ) {}

    /**
     * @return int How many steps were taken.
     */
    public function run(CarbonImmutable $today): int
    {
        $steps = 0;

        foreach ($this->open() as $dispute) {
            try {
                $steps += $this->chase($dispute, $today) ? 1 : 0;
            } catch (Throwable $e) {
                // One dispute's mail trouble mustn't stop the others; the
                // step is tried again tomorrow.
                report($e);
            }
        }

        return $steps;
    }

    /** Days after a request for information that we remind the customer. */
    public const INFO_REMINDER_DAYS = 3;

    private function chase(Dispute $dispute, CarbonImmutable $today): bool
    {
        $rules = $dispute->property->rules();

        if ($dispute->waitingOnCustomer()) {
            if ($dispute->info_reminded_on === null && Dates::daysBetween($dispute->info_requested_on, $today) >= self::INFO_REMINDER_DAYS) {
                $this->remindAboutInformation($dispute, $today);

                return true;
            }

            return false;
        }

        if ($dispute->acknowledged_on === null && $dispute->followed_up_on === null
            && Dates::daysBetween(CaseClock::replyDue($dispute->lodged_on, $rules), $today) > 0) {
            $this->mailer->followUp($dispute);

            return true;
        }

        // Counted from when the customer sent what was asked for, if they
        // were asked; otherwise from when the dispute went out.
        $councillorWindowEnds = CaseClock::publicProtectorEscalationOn(
            $dispute->info_answered_on !== null
                ? CaseClock::councillorEscalationOn($dispute->info_answered_on, $rules)
                : ($dispute->escalated_councillor_on ?? CaseClock::councillorEscalationOn($dispute->lodged_on, $rules)),
            $rules,
        );

        if (Dates::daysBetween($councillorWindowEnds, $today) <= 0) {
            return false;
        }

        $manager = $dispute->property->municipality?->escalation_email;

        if ($dispute->escalated_manager_on === null && $manager !== null) {
            $this->mailer->escalate($dispute, $manager);

            return true;
        }

        $managerHadTime = $dispute->escalated_manager_on === null
            || Dates::daysBetween(CaseClock::replyDue($dispute->escalated_manager_on, $rules), $today) > 0;

        if ($dispute->public_protector_draft_on === null && $managerHadTime) {
            $this->draftPublicProtector($dispute, $today);

            return true;
        }

        return false;
    }

    private function remindAboutInformation(Dispute $dispute, CarbonImmutable $today): void
    {
        $what = $dispute->events()->where('kind', DisputeEventKind::InfoRequested)->latest('id')->value('note');
        $name = $dispute->property->municipality?->short_name ?? 'The municipality';

        $this->mail->send($dispute->organisation, new CustomerNotice(
            kind: 'info_reminder',
            subject: "{$name} is still waiting for something from you",
            lines: [
                "{$name} asked for something before they can decide your {$dispute->bill->monthLabel()} dispute".($what ? ": {$what}" : '.'),
                "Reply to their email, which you were copied on, or send it to us. Then tell us on the dispute page that you've sent it. We won't escalate while they're waiting on you.",
            ],
            actionText: 'Open the dispute',
            actionUrl: route('disputes.show', $dispute),
        ));

        $dispute->update(['info_reminded_on' => $today]);
    }

    private function draftPublicProtector(Dispute $dispute, CarbonImmutable $today): void
    {
        $organisation = $dispute->organisation;
        $sender = $organisation->kind === OrganisationKind::Household
            ? ($dispute->bill->user?->name ?? $organisation->users()->orderBy('id')->value('name') ?? $organisation->name)
            : $organisation->name;

        $letter = LetterWriter::publicProtector(
            municipalityName: $dispute->property->municipality?->name ?? 'the municipality',
            senderName: $sender,
            accountNumber: $dispute->bill->account_number ?? $dispute->property->account_number ?? '[account number]',
            propertyAddress: (string) $dispute->property->address,
            lodgedOn: $dispute->lodged_on,
            followedUpOn: $dispute->followed_up_on,
            escalatedOn: $dispute->escalated_manager_on,
            reference: (string) $dispute->reference,
            disputedTotal: Money::of($dispute->disputed_total),
            letterDate: $today,
        );

        $this->mail->publicProtectorDraft($dispute, $letter->subject, $letter->body);
        $this->disputes->record($dispute, DisputeEventKind::Note, $today, ['note' => 'Public Protector complaint drafted and emailed to you to lodge.']);
        $dispute->update(['public_protector_draft_on' => $today]);
    }

    /**
     * @return Collection<int, Dispute>
     */
    private function open(): Collection
    {
        return Dispute::query()
            ->whereNotNull('sent_to')
            ->whereNotNull('lodged_on')
            ->whereNull('reply_received_on')
            ->whereNull('credit_on')
            ->whereNull('closed_on')
            ->whereNull('public_protector_draft_on')
            ->with(['property.municipality', 'bill.user', 'organisation'])
            ->orderBy('id')
            ->get();
    }
}
