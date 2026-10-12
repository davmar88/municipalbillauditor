<?php

namespace App\Services\Disputes;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Letters\LetterWriter;
use App\Audit\Money;
use App\Enums\DisputeEventKind;
use App\Enums\OrganisationKind;
use App\Mail\DisputeEmail;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\User;
use App\Notifications\CustomerNotice;
use App\Services\CustomerMail;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Emails disputes to the municipality, on the customer's behalf: the
 * dispute itself, a follow-up on the same thread, and the escalation to the
 * municipal manager. Replies go to the dispute's own address, so we can
 * read them; the customer is copied in on everything.
 */
class DisputeMailer
{
    public const NO_MANDATE = 'no_mandate';

    public const NO_ADDRESS = 'no_address';

    public function __construct(
        private readonly RecipientFinder $recipients,
        private readonly DisputeService $disputes,
        private readonly CustomerMail $mail,
    ) {}

    /**
     * Send an approved dispute. Without the customer's mandate, or without
     * an address for the municipality, it is held and nothing is sent.
     * Returns the address it went to.
     */
    public function send(Dispute $dispute): ?string
    {
        $dispute->loadMissing(['organisation', 'property.municipality', 'bill']);

        if ($dispute->lodged_on !== null) {
            return $dispute->sent_to;
        }

        if (! $dispute->organisation->hasMandate()) {
            $this->hold($dispute, self::NO_MANDATE);

            return null;
        }

        $recipient = $this->recipients->for($dispute);

        if ($recipient === null) {
            $this->hold($dispute, self::NO_ADDRESS);

            return null;
        }

        $dispute->reply_token ??= Str::lower(Str::random(24));
        $sender = $this->senderName($dispute);
        $body = implode("\n", [
            "We are sending this dispute on behalf of {$sender}, who has authorised us to lodge and follow up billing disputes about this account. Please reply to this email: your reply reaches us and {$sender}.",
            '',
            $dispute->letter_body,
        ]);

        $this->deliver($dispute, 'dispute', $recipient['email'], $dispute->letter_subject, $body, attach: true);

        $dispute->fill([
            'sent_to' => $recipient['email'],
            'sent_to_source' => $recipient['source'],
            'held_reason' => null,
            'held_since' => null,
        ])->save();

        $this->disputes->lodge($dispute, CarbonImmutable::today(), null, null, "Emailed to {$recipient['email']}");
        $this->mail->disputeSent($dispute, $recipient['email']);

        return $recipient['email'];
    }

    /**
     * No acknowledgement by the reply date: ask again, on the same thread.
     */
    public function followUp(Dispute $dispute): void
    {
        $rules = $dispute->property->rules();
        $letter = LetterWriter::followUp(
            senderName: $this->senderName($dispute),
            accountNumber: $this->accountNumber($dispute),
            lodgedOn: $dispute->lodged_on,
            replyDueOn: CaseClock::replyDue($dispute->lodged_on, $rules),
            disputedTotal: Money::of($dispute->disputed_total),
            originalSubject: $dispute->letter_subject,
        );

        $this->deliver($dispute, 'follow_up', (string) $dispute->sent_to, $letter->subject, $letter->body."\n\n".$this->quoted($dispute));
        $this->disputes->record($dispute, DisputeEventKind::FollowedUp, CarbonImmutable::today(), ['note' => "Followed up by email to {$dispute->sent_to}"]);
        $dispute->update(['followed_up_on' => CarbonImmutable::today()]);
    }

    /**
     * Still unanswered after the councillor's window: write to the
     * municipal manager, when the directory has their address.
     */
    public function escalate(Dispute $dispute, string $to): void
    {
        $letter = LetterWriter::escalation(
            municipalityName: $dispute->property->municipality?->name ?? 'the municipality',
            senderName: $this->senderName($dispute),
            accountNumber: $this->accountNumber($dispute),
            propertyAddress: (string) $dispute->property->address,
            lodgedOn: $dispute->lodged_on,
            followedUpOn: $dispute->followed_up_on,
            reference: (string) $dispute->reference,
            disputedTotal: Money::of($dispute->disputed_total),
            letterDate: CarbonImmutable::today(),
        );

        $this->deliver($dispute, 'escalation', $to, $letter->subject, $letter->body."\n\n".$this->quoted($dispute), attach: true, thread: false);
        $this->disputes->record($dispute, DisputeEventKind::EscalatedManager, CarbonImmutable::today(), ['note' => "Escalated by email to {$to}"]);
        $dispute->update(['escalated_manager_on' => CarbonImmutable::today()]);
    }

    /**
     * Send an email about the dispute and keep a copy.
     */
    private function deliver(Dispute $dispute, string $kind, string $to, string $subject, string $body, bool $attach = false, bool $thread = true): void
    {
        $dispute->reply_token ??= Str::lower(Str::random(24));
        $dispute->save();

        $domain = (string) config('auditor.inbound_email_domain');
        $messageId = 'dispute-'.$dispute->id.'-'.Str::lower(Str::random(12)).'@'.$domain;
        $earlier = $thread ? $dispute->messages()->where('direction', DisputeMessage::OUT)->whereNotNull('message_id')->pluck('message_id')->all() : [];
        $cc = $this->customerEmails($dispute);

        Mail::to($to)->send(new DisputeEmail(
            mailSubject: $subject,
            body: $body,
            replyAddress: $dispute->replyAddress(),
            copyTo: $cc,
            messageId: $messageId,
            files: $attach ? $this->attachments($dispute) : [],
            inReplyTo: $earlier !== [] ? end($earlier) : null,
            references: $earlier,
        ));

        $dispute->messages()->create([
            'direction' => DisputeMessage::OUT,
            'kind' => $kind,
            'from_address' => (string) config('mail.from.address'),
            'to_address' => $to,
            'cc_address' => implode(', ', $cc),
            'subject' => $subject,
            'body' => $body,
            'message_id' => $messageId,
            'in_reply_to' => $earlier !== [] ? end($earlier) : null,
        ]);
    }

    private function hold(Dispute $dispute, string $reason): void
    {
        $already = $dispute->held_reason === $reason;
        $dispute->update(['held_reason' => $reason, 'held_since' => $dispute->held_since ?? now()]);

        if ($already || $reason !== self::NO_ADDRESS) {
            return;
        }

        $this->mail->disputeHeld($dispute);

        $staff = User::query()->where('is_staff', true)->whereNotNull('email_verified_at')->get();

        if ($staff->isNotEmpty()) {
            Notification::send($staff, new CustomerNotice(
                kind: 'staff_dispute_held',
                subject: 'A dispute is waiting for a municipality email address',
                lines: [
                    "{$dispute->organisation->name}'s dispute for ".($dispute->property->municipality?->name ?? 'an unknown municipality')
                        .' is held: we have no address for account queries. Add a verified one in the reference data and send it from the work queue.',
                ],
                actionText: 'Open the work queue',
                actionUrl: route('staff.index'),
            ));
        }
    }

    /**
     * @return list<array{disk: string, path: string, name: string, mime: ?string}>
     */
    private function attachments(Dispute $dispute): array
    {
        $bill = $dispute->bill;
        $disk = (string) config('auditor.bill_disk');

        return array_values(array_filter([
            $bill->file_path ? ['disk' => $disk, 'path' => $bill->file_path, 'name' => $bill->file_name ?? 'bill', 'mime' => $bill->file_mime] : null,
            $bill->meter_photo_path ? ['disk' => $disk, 'path' => $bill->meter_photo_path, 'name' => 'meter-photo.'.pathinfo($bill->meter_photo_path, PATHINFO_EXTENSION), 'mime' => null] : null,
        ]));
    }

    /**
     * @return list<string>
     */
    private function customerEmails(Dispute $dispute): array
    {
        return $dispute->organisation->users()->whereNotNull('email_verified_at')->orderBy('id')->pluck('email')->all();
    }

    private function quoted(Dispute $dispute): string
    {
        return "----- The dispute sent on {$dispute->lodged_on?->format('j F Y')} -----\n\n".$dispute->letter_body;
    }

    private function senderName(Dispute $dispute): string
    {
        $organisation = $dispute->organisation;

        if ($organisation->kind !== OrganisationKind::Household) {
            return $organisation->name;
        }

        return $dispute->bill?->user?->name ?? $organisation->users()->orderBy('id')->value('name') ?? $organisation->name;
    }

    private function accountNumber(Dispute $dispute): string
    {
        return $dispute->bill?->account_number ?? $dispute->property->account_number ?? '[account number]';
    }
}
