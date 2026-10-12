<?php

namespace App\Jobs;

use App\Audit\Money;
use App\Enums\DisputeEventKind;
use App\Enums\ReplyClassification;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Services\Claude\ClaudeCosts;
use App\Services\CustomerMail;
use App\Services\DisputeService;
use App\Services\Replies\ReplyReader;
use App\Services\Replies\ReplyReading;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a municipality's reply to a dispute, records what it means on the
 * dispute, and tells the customer in plain words, with what they need to do.
 */
class ReadDisputeReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public DisputeMessage $message) {}

    public function handle(ReplyReader $reader, ClaudeCosts $costs, DisputeService $disputes, CustomerMail $mail): void
    {
        $message = $this->message;

        if ($message->read_at !== null) {
            return;
        }

        $dispute = $message->dispute()->with(['organisation', 'property.municipality', 'bill'])->firstOrFail();
        $reading = $reader->read($this->context($dispute), (string) $message->subject, (string) $message->body);

        if ($reading->usage !== null) {
            $costs->record($reading->usage, $dispute->organisation, $dispute, 'reply_read');
        }

        $message->update([
            'classification' => $reading->classification,
            'reading' => $reading->toArray(),
            'read_at' => now(),
        ]);

        if (! $reading->classification->matters() || $dispute->lodged_on === null) {
            return;
        }

        $this->record($dispute, $reading, $disputes);
        $this->tell($dispute, $reading, $mail);
    }

    private function record(Dispute $dispute, ReplyReading $reading, DisputeService $disputes): void
    {
        $today = CarbonImmutable::today();
        $note = $reading->summary;

        match ($reading->classification) {
            ReplyClassification::Acknowledgement => $dispute->acknowledged_on === null
                ? $disputes->record($dispute, DisputeEventKind::Acknowledged, $today, ['reference' => $reading->reference, 'note' => $note])
                : $disputes->record($dispute, DisputeEventKind::Note, $today, ['note' => $note]),
            ReplyClassification::MoreInformation => $disputes->record($dispute, DisputeEventKind::InfoRequested, $today, ['note' => $reading->informationRequested ?? $note]),
            ReplyClassification::Upheld => $disputes->record($dispute, DisputeEventKind::Upheld, $today, [
                'note' => $reading->creditAmount !== null ? 'Credit of '.Money::format($reading->creditAmount).' promised. '.$note : $note,
            ]),
            ReplyClassification::Rejected => $disputes->record($dispute, DisputeEventKind::Rejected, $today, ['note' => $reading->reason ?? $note]),
            default => null,
        };

        // A reference in any reply is worth keeping.
        if ($reading->reference !== null && $dispute->refresh()->reference === null) {
            $dispute->update(['reference' => $reading->reference]);
        }
    }

    private function tell(Dispute $dispute, ReplyReading $reading, CustomerMail $mail): void
    {
        $name = $dispute->property->municipality?->short_name ?? 'The municipality';
        $month = $dispute->bill->monthLabel();

        [$subject, $todo] = match ($reading->classification) {
            ReplyClassification::Acknowledgement => [
                "{$name} has received your {$month} dispute",
                null,
            ],
            ReplyClassification::MoreInformation => [
                "{$name} needs something from you for your {$month} dispute",
                $reading->customerAction ?? ('What they need: '.($reading->informationRequested ?? 'see their email').'. Reply to their email, which we were copied on, or send it to us and we will pass it on.'),
            ],
            ReplyClassification::Upheld => [
                "Good news on your {$month} dispute",
                "We'll watch your next bills for the credit and tell you when it shows.",
            ],
            default => [
                "{$name} turned down your {$month} dispute",
                $reading->customerAction ?? 'If you disagree with their reason, reply to us and we will help you take it further.',
            ],
        };

        $lines = [$reading->summary];

        if ($reading->reference !== null) {
            $lines[] = "Their reference: {$reading->reference}.";
        }

        if ($reading->classification === ReplyClassification::Upheld && $reading->creditAmount !== null) {
            $lines[] = 'They promised a credit of '.Money::format($reading->creditAmount).'.';
        }

        if ($reading->classification === ReplyClassification::Rejected && $reading->reason !== null) {
            $lines[] = "Their reason: {$reading->reason}";
        }

        $mail->municipalityReplied($dispute, $subject, $lines, $todo);
    }

    /**
     * What the reader needs to know to match a reply to the dispute.
     */
    private function context(Dispute $dispute): string
    {
        return implode("\n", array_filter([
            'Municipality: '.($dispute->property->municipality?->name ?? 'unknown'),
            'Account: '.($dispute->bill->account_number ?? $dispute->property->account_number ?? 'unknown'),
            'Disputed: '.Money::format($dispute->disputed_total)." on the {$dispute->bill->monthLabel()} bill",
            $dispute->reference ? "Reference so far: {$dispute->reference}" : null,
            "Subject of our dispute: {$dispute->letter_subject}",
        ]));
    }
}
