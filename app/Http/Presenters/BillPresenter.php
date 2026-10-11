<?php

namespace App\Http\Presenters;

use App\Audit\Deadlines\CaseClock;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Money;
use App\Enums\BillStatus;
use App\Enums\FindingState;
use App\Models\Bill;
use App\Models\Charge;
use App\Models\Finding;
use App\Models\MeterReading;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shapes bills for the React pages. Amounts and dates are formatted here so
 * every screen shows exactly the figures the engine worked with.
 */
class BillPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Bill $bill): array
    {
        $findings = $bill->relationLoaded('findings') ? $bill->findings : $bill->findings()->get();
        $open = $findings->filter(fn (Finding $f) => $f->state !== FindingState::Dismissed);
        $totals = self::totals($open);
        $dispute = $bill->relationLoaded('dispute') ? $bill->dispute : null;

        return [
            'id' => $bill->id,
            'month' => $bill->monthLabel(),
            'billDate' => $bill->bill_date?->toDateString(),
            'totalDue' => $bill->total_due !== null ? Money::format($bill->total_due) : null,
            'status' => $bill->status->value,
            'statusLabel' => $bill->status->label(),
            'source' => $bill->source->value,
            'needsReview' => $bill->needs_review,
            'property' => $bill->property ? ['id' => $bill->property->id, 'label' => $bill->property->label()] : null,
            'flags' => $open->count(),
            'questions' => $open->filter(fn (Finding $f) => $f->awaitingAnswer())->count(),
            'likelyTotal' => $totals['likely'],
            'upToTotal' => $totals['upTo'],
            'dispute' => $dispute ? [
                'id' => $dispute->id,
                'stage' => $dispute->submitted_on ? $dispute->stage()->value : 'draft',
                'stageLabel' => $dispute->submitted_on ? $dispute->stage()->label() : 'Draft',
                'submitted' => $dispute->submitted_on !== null,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Bill $bill): array
    {
        $bill->loadMissing(['readings', 'charges', 'findings', 'property.metro', 'dispute']);
        $findings = $bill->findings;
        $flaggedBy = self::flagIndex($findings);
        $metro = $bill->property?->metro;
        $deadline = null;

        if ($bill->bill_date !== null && $metro !== null) {
            $date = CaseClock::disputeDeadline($bill->bill_date, $metro->rules());
            $deadline = [
                'date' => $date->format('j M Y'),
                'daysLeft' => (int) CarbonImmutable::today()->diffInDays($date, false),
                'verified' => $metro->rules_verified,
            ];
        }

        return [
            ...self::summary($bill),
            'accountNumber' => $bill->account_number,
            'tariffLabel' => $bill->tariff_label,
            'tariffCategory' => $bill->tariff_category->value,
            'billDateLabel' => $bill->bill_date?->format('j M Y'),
            'dueDate' => $bill->due_date?->format('j M Y'),
            'period' => $bill->period_start && $bill->period_end
                ? $bill->period_start->format('j M').' to '.$bill->period_end->format('j M Y')
                : null,
            'metro' => $metro ? ['name' => $metro->name, 'shortName' => $metro->short_name] : null,
            'readings' => $bill->readings->map(fn (MeterReading $r) => [
                'id' => $r->id,
                'service' => $r->service->value,
                'serviceLabel' => $r->service->label(),
                'meterNumber' => $r->meter_number,
                'previous' => $r->previous !== null ? Money::quantity($r->previous) : null,
                'current' => $r->current !== null ? Money::quantity($r->current) : null,
                'consumption' => $r->consumption !== null ? Money::quantity($r->consumption) : null,
                'unit' => $r->unit ?: $r->service->defaultUnit(),
                'readingType' => $r->reading_type->value,
                'readingTypeShort' => match ($r->reading_type) {
                    ReadingType::Actual => 'A',
                    ReadingType::Estimated => 'E',
                    ReadingType::Unknown => '–',
                },
                'flags' => $flaggedBy[$r->service->value] ?? [],
            ])->values(),
            'charges' => $bill->charges->map(fn (Charge $c) => [
                'id' => $c->id,
                'service' => $c->service->value,
                'description' => $c->description,
                'amount' => Money::format($c->amount),
                'isCredit' => Money::of($c->amount)->isNegative(),
                'flags' => $c->service->isMetered() ? ($flaggedBy[$c->service->value] ?? []) : [],
            ])->values(),
            'tariffFlags' => $flaggedBy['tariff'] ?? [],
            'findings' => $findings->map(fn (Finding $f) => self::finding($f))->values(),
            'passed' => $bill->passed ?? [],
            'skipped' => $bill->skipped ?? [],
            'reviewNote' => $bill->review_note,
            'readMethod' => $bill->read_method,
            'file' => $bill->file_path ? ['name' => $bill->file_name, 'url' => route('bills.file', $bill)] : null,
            'meterPhoto' => $bill->meter_photo_path ? route('bills.meter-photo.show', $bill) : null,
            'deadline' => $deadline,
            'locked' => $bill->isLocked(),
            'canDispute' => $bill->status === BillStatus::Checked && $findings->contains(fn (Finding $f) => $f->disputable()),
            'history' => self::history($bill),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function finding(Finding $finding): array
    {
        return [
            'id' => $finding->id,
            'number' => $finding->number,
            'checkType' => $finding->check_type->value,
            'checkLabel' => $finding->check_type->shortLabel(),
            'service' => $finding->service?->value,
            'confidence' => $finding->confidence->value,
            'confidenceLabel' => $finding->confidence->label(),
            'state' => $finding->state->value,
            'title' => $finding->title,
            'explanation' => $finding->explanation,
            'amount' => $finding->amount !== null ? Money::format($finding->amount) : null,
            'amountIsUpperBound' => $finding->amount_is_upper_bound,
            'evidence' => $finding->evidence,
            'calculation' => $finding->calculation,
            'question' => $finding->question,
            'answerOptions' => $finding->answer_options ?? [],
            'answer' => $finding->answer,
            'action' => $finding->action,
            'awaitingAnswer' => $finding->awaitingAnswer(),
            'disputable' => $finding->disputable(),
        ];
    }

    /**
     * Likely errors on their own, and the most this bill could be wrong by.
     *
     * @param  Collection<int, Finding>  $findings
     * @return array{likely: ?string, possible: ?string, upTo: ?string}
     */
    public static function totals(Collection $findings): array
    {
        $amounts = fn (Collection $set) => Money::sum($set->filter(fn (Finding $f) => $f->amount !== null)->map(fn (Finding $f) => Money::of($f->amount)));
        $likely = $amounts($findings->filter(fn (Finding $f) => $f->confidence === Confidence::Likely));
        $possible = $amounts($findings->filter(fn (Finding $f) => $f->confidence !== Confidence::Likely));
        $upTo = $likely->plus($possible);

        return [
            'likely' => $likely->isZero() ? null : Money::format($likely),
            'possible' => $possible->isZero() ? null : Money::format($possible),
            'upTo' => $upTo->isZero() ? null : Money::format($upTo),
        ];
    }

    /**
     * Which findings point at each service (and the tariff), so the bill can
     * be marked up with their numbers.
     *
     * @param  Collection<int, Finding>  $findings
     * @return array<string, list<int>>
     */
    private static function flagIndex(Collection $findings): array
    {
        $index = [];

        foreach ($findings as $finding) {
            if ($finding->state === FindingState::Dismissed) {
                continue;
            }

            $key = $finding->service?->value ?? 'tariff';
            $index[$key][] = $finding->number;
        }

        return $index;
    }

    /**
     * Twelve months of use per metered service, for the history charts.
     *
     * @return list<array<string, mixed>>
     */
    private static function history(Bill $bill): array
    {
        if ($bill->property_id === null || $bill->bill_date === null) {
            return [];
        }

        $bills = Bill::query()
            ->where('property_id', $bill->property_id)
            ->where('status', BillStatus::Checked)
            ->whereNotNull('bill_date')
            ->where('bill_date', '<=', $bill->bill_date)
            ->orderByDesc('bill_date')
            ->limit(12)
            ->with(['readings', 'findings'])
            ->get()
            ->reverse()
            ->values();

        $series = [];

        foreach (Service::metered() as $service) {
            $points = [];

            foreach ($bills as $past) {
                $readings = $past->readings->where('service', $service);

                if ($readings->isEmpty() || $readings->contains(fn (MeterReading $r) => $r->consumption === null)) {
                    continue;
                }

                $flagged = $past->findings->contains(
                    fn (Finding $f) => $f->service === $service && $f->state !== FindingState::Dismissed,
                );

                $points[] = [
                    'billId' => $past->id,
                    'month' => $past->month()?->format('M') ?? '',
                    'label' => $past->monthLabel(),
                    'value' => (float) Money::sum($readings->map(fn (MeterReading $r) => Money::of($r->consumption)))->toFloat(),
                    'estimated' => $readings->contains(fn (MeterReading $r) => $r->reading_type === ReadingType::Estimated),
                    'flagged' => $flagged,
                    'current' => $past->id === $bill->id,
                ];
            }

            if (count($points) < 2) {
                continue;
            }

            $actual = array_filter($points, fn ($p) => ! $p['estimated'] && ! $p['current']);
            $average = $actual === [] ? null : round(array_sum(array_column($actual, 'value')) / count($actual), $service->quantityScale());

            $series[] = [
                'service' => $service->value,
                'label' => $service->label(),
                'unit' => $service->defaultUnit(),
                'average' => $average,
                'points' => $points,
            ];
        }

        return $series;
    }
}
