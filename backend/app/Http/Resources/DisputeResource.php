<?php

namespace App\Http\Resources;

use App\Models\Dispute;
use App\Models\DisputeEvent;
use Illuminate\Http\Request;

/**
 * Single-dispute shape: includes letter_body and events.
 *
 * @mixin Dispute
 */
class DisputeResource extends ApiResource
{
    protected bool $withDetails = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $bill = $this->bill;
        $property = $bill->property;
        $nextStep = $this->nextStep();

        $data = [
            'id' => $this->id,
            'bill_id' => $this->bill_id,
            'property' => [
                'id' => $property->id,
                'nickname' => $property->nickname,
                'metro' => $property->metro,
            ],
            'bill' => [
                'id' => $bill->id,
                'bill_date' => $bill->bill_date?->toDateString(),
            ],
            'status' => $this->status->value,
            'finding_ids' => array_map('intval', $this->finding_ids ?? []),
            'amount_disputed_cents' => $this->amount_disputed_cents,
            'letter_subject' => $this->withDetails ? $this->letter_subject : $this->maskedSubject(),
        ];

        if ($this->withDetails) {
            $data['letter_body'] = $this->letter_body;
        }

        $data += [
            'channel' => $this->channel?->value,
            'municipality_reference' => $this->municipality_reference,
            'lodge_deadline' => $bill->disputeDeadline()?->toDateString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'response_due_at' => $this->response_due_at?->toISOString(),
            'escalation_level' => $this->escalation_level,
            'next_step' => $nextStep === null ? null : [
                'name' => $nextStep['name'],
                'description' => $nextStep['description'],
                'due_at' => $nextStep['due_at']?->toISOString(),
            ],
            'outcome_amount_cents' => $this->outcome_amount_cents,
            'resolved_at' => $this->resolved_at?->toISOString(),
        ];

        if ($this->withDetails) {
            $data['events'] = $this->events->map(fn (DisputeEvent $event) => [
                'id' => $event->id,
                'type' => $event->type->value,
                'note' => $event->note,
                'occurred_at' => $event->occurred_at->toISOString(),
            ])->values()->all();
        }

        $data['created_at'] = $this->created_at?->toISOString();

        return $data;
    }

    /**
     * The subject with account numbers masked, for list responses (least
     * exposure, docs/architecture.md). The generated subject contains the
     * full account number; an edited one may contain any long number.
     */
    protected function maskedSubject(): ?string
    {
        $subject = $this->letter_subject;
        if ($subject === null) {
            return null;
        }

        $property = $this->bill->property;
        $account = trim((string) $property->account_number);
        if ($account !== '') {
            $subject = str_replace($account, $property->maskedAccountNumber(), $subject);
        }

        return preg_replace_callback('/\d{6,}/', fn (array $m) => '••••'.substr($m[0], -4), $subject) ?? $subject;
    }
}
