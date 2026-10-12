<?php

namespace App\Http\Presenters;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Deadlines\Stage;
use App\Audit\Deadlines\TimelineStep;
use App\Audit\Enums\CheckType;
use App\Audit\Enums\TariffCategory;
use App\Audit\Money;
use App\Enums\DisputeEventKind;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\DisputeMessage;
use App\Models\Finding;
use Carbon\CarbonImmutable;

class DisputePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Dispute $dispute): array
    {
        $dispute->loadMissing(['property.municipality', 'bill', 'findings']);
        $submitted = $dispute->submitted_on !== null;
        $stage = $submitted ? $dispute->stage() : Stage::Draft;
        $status = $submitted ? $dispute->status() : null;

        return [
            'id' => $dispute->id,
            'billId' => $dispute->bill_id,
            'mode' => $dispute->mode->value,
            'property' => $dispute->property->label(),
            'billMonth' => $dispute->bill->monthLabel(),
            'disputedTotal' => Money::format($dispute->disputed_total),
            'submitted' => $submitted,
            'awaitingLodging' => $submitted && $dispute->lodged_on === null,
            'stage' => $stage->value,
            'stageLabel' => $submitted && $dispute->lodged_on === null ? 'Waiting to be sent' : $stage->label(),
            'needsAction' => $submitted && $stage->needsAction(),
            'isOpen' => $stage->isOpen(),
            'nextDate' => $status?->nextDate?->format('j M Y'),
            'daysLeft' => $status?->daysLeft,
            'lodgedOn' => $dispute->lodged_on?->format('j M Y'),
            'reference' => $dispute->reference,
            'issues' => $dispute->findings->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Dispute $dispute): array
    {
        $dispute->loadMissing(['property.municipality', 'bill', 'findings', 'events', 'organisation']);
        $today = CarbonImmutable::today();
        $rules = $dispute->property->rules();
        $facts = $dispute->facts();
        $status = CaseClock::status($facts, $rules, $today);
        $stage = $status->stage;
        $awaitingLodging = $dispute->submitted_on !== null && $dispute->lodged_on === null;

        $timeline = array_map(fn (TimelineStep $step) => [
            'key' => $step->key,
            'title' => $awaitingLodging && $step->key === 'lodged' ? 'Waiting to be sent' : $step->title,
            'detail' => match (true) {
                $awaitingLodging && $step->key === 'lodged' => 'Approved '.$dispute->submitted_on->format('j M'),
                $step->key === 'lodged' && $dispute->sent_to !== null => "Emailed to {$dispute->sent_to}",
                default => $step->detail,
            },
            'on' => $step->on?->format('j M'),
            'state' => $step->state,
        ], CaseClock::timeline($facts, $rules, $today));

        return [
            ...self::summary($dispute),
            'letterSubject' => $dispute->letter_subject,
            'letterBody' => $dispute->letter_body,
            'undisputedAmount' => $dispute->undisputed_amount !== null ? Money::format($dispute->undisputed_amount) : null,
            'municipality' => $dispute->property->municipality?->name,
            'status' => [
                'headline' => $awaitingLodging ? 'Waiting to be sent' : $status->headline,
                'countdownLabel' => $awaitingLodging ? '' : $status->countdownLabel,
                'daysLeft' => $awaitingLodging ? null : $status->daysLeft,
                'progress' => $awaitingLodging ? null : $status->progress,
                'note' => $awaitingLodging
                    ? match ($dispute->held_reason) {
                        'no_mandate' => 'We need your permission to send disputes for you. Switch it on in your account settings.',
                        'no_address' => "We don't have ".($dispute->property->municipality?->name ?? 'the municipality')."'s account queries email yet. Our team is finding it, and the dispute goes out as soon as they do.",
                        'send_failed' => "Our email didn't go through, so our team is sending it. You don't need to do anything.",
                        default => "We're emailing your dispute now. You'll be copied in.",
                    }
                    : $status->note,
            ],
            'sentTo' => $dispute->sent_to,
            'messages' => $dispute->messages()->where('kind', '!=', 'customer')->get()->map(fn (DisputeMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'kind' => $m->kind,
                'subject' => $m->subject,
                'on' => $m->created_at->format('j M Y'),
                'who' => $m->direction === DisputeMessage::OUT ? "To {$m->to_address}" : "From {$m->from_address}",
                'classification' => $m->classification?->label(),
                'summary' => $m->classification?->matters() ? ($m->reading['summary'] ?? null) : null,
                'files' => array_map(fn (int $i) => [
                    'name' => $m->attachments[$i]['name'],
                    'url' => route('disputes.messages.file', [$dispute, $m, $i]),
                ], array_keys($m->attachments ?? [])),
            ])->values(),
            'timeline' => $timeline,
            'events' => $dispute->events->map(fn (DisputeEvent $e) => [
                'id' => $e->id,
                'kind' => $e->kind->value,
                'label' => $e->kind->label(),
                'on' => $e->happened_on->format('j M Y'),
                'note' => $e->note,
            ])->values(),
            'findings' => $dispute->findings->map(fn (Finding $f) => [
                'number' => $f->number,
                'title' => $f->title,
                'amount' => $f->amount !== null ? Money::format($f->amount) : null,
            ])->values(),
            'actions' => $dispute->lodged_on !== null ? self::actions($stage, $dispute->reference !== null, $dispute->mode->isManagedByUs()) : [],
            'creditAmount' => $dispute->credit_amount !== null ? Money::format($dispute->credit_amount) : null,
            'tariff' => self::tariff($dispute),
        ];
    }

    /**
     * What the customer can record next, given where the dispute is.
     *
     * @return list<array{kind: string, label: string, fields: list<string>}>
     */
    private static function actions(Stage $stage, bool $hasReference, bool $managed): array
    {
        if (! $stage->isOpen()) {
            return [];
        }

        $actions = $hasReference
            ? []
            : [['kind' => DisputeEventKind::Acknowledged->value, 'label' => 'Add the reference number', 'fields' => ['reference']]];

        $actions[] = ['kind' => DisputeEventKind::ReplyReceived->value, 'label' => 'The municipality replied', 'fields' => ['note']];

        // Our team escalates the disputes it manages, from the staff queue.
        if (! $managed && in_array($stage, [Stage::ReplyOverdue, Stage::AwaitingReply], true)) {
            $actions[] = ['kind' => DisputeEventKind::EscalatedCouncillor->value, 'label' => 'I escalated to my councillor', 'fields' => ['note']];
        }

        if (! $managed && in_array($stage, [Stage::WithCouncillor, Stage::CouncillorOverdue], true)) {
            $actions[] = ['kind' => DisputeEventKind::EscalatedPublicProtector->value, 'label' => 'I went to the Public Protector', 'fields' => ['note']];
        }

        $actions[] = ['kind' => DisputeEventKind::CreditReceived->value, 'label' => 'The credit is on my account', 'fields' => ['amount']];
        $actions[] = ['kind' => DisputeEventKind::Closed->value, 'label' => 'Close this dispute', 'fields' => ['note']];

        return $actions;
    }

    private static function expectedMonthlySaving(Dispute $dispute): ?string
    {
        $tariff = $dispute->findings->first(fn (Finding $f) => $f->check_type === CheckType::WrongTariff);

        return $tariff?->data['monthly_overcharge'] ?? null;
    }

    /**
     * The tariff before and after the fix, for tariff claims.
     *
     * @return array<string, mixed>|null
     */
    private static function tariff(Dispute $dispute): ?array
    {
        $finding = $dispute->findings->first(fn (Finding $f) => $f->check_type === CheckType::WrongTariff);

        if ($finding === null) {
            return null;
        }

        $expected = TariffCategory::tryFrom((string) ($finding->data['expected_category'] ?? ''));
        $after = $dispute->tariff_fixed_on !== null
            ? Bill::query()
                ->where('property_id', $dispute->property_id)
                ->where('tariff_category', $expected)
                ->where('bill_date', '>', $dispute->bill->bill_date)
                ->orderBy('bill_date')
                ->first()
            : null;

        return [
            'before' => [
                'month' => $dispute->bill->monthLabel(),
                'label' => $dispute->bill->tariff_label ?? 'Business',
                'total' => $dispute->bill->total_due !== null ? Money::format($dispute->bill->total_due) : null,
            ],
            'after' => $after ? [
                'month' => $after->monthLabel(),
                'label' => $after->tariff_label ?? $expected?->label(),
                'total' => $after->total_due !== null ? Money::format($after->total_due) : null,
            ] : null,
            'expected' => $expected?->label(),
            'monthlySaving' => ($saving = $dispute->monthly_saving ?? self::expectedMonthlySaving($dispute)) !== null ? Money::format($saving) : null,
        ];
    }
}
