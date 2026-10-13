<?php

namespace App\Services;

use App\Audit\Auditor;
use App\Audit\Checks\Usage;
use App\Audit\Data\BillSnapshot;
use App\Audit\Data\ChargeLine;
use App\Audit\Data\CheckContext;
use App\Audit\Data\CheckReport;
use App\Audit\Data\OutageWindow;
use App\Audit\Data\Reading;
use App\Audit\Enums\CheckType;
use App\Audit\Money;
use App\Enums\BillStatus;
use App\Enums\FindingState;
use App\Models\Bill;
use App\Models\Charge;
use App\Models\Finding;
use App\Models\MeterReading;
use App\Models\Outage;
use App\Models\TariffRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Runs the four checks on a stored bill and stores what they find.
 */
class AuditService
{
    public function __construct(
        private readonly CreditWatcher $credits,
        private readonly Auditor $auditor = new Auditor,
    ) {}

    public static function snapshot(Bill $bill): BillSnapshot
    {
        return new BillSnapshot(
            billDate: $bill->bill_date,
            periodStart: $bill->period_start,
            periodEnd: $bill->period_end,
            tariff: $bill->tariff_category,
            tariffLabel: (string) $bill->tariff_label,
            totalDue: Money::ofNullable($bill->total_due),
            readings: $bill->readings->map(fn (MeterReading $r) => new Reading(
                service: $r->service,
                consumption: Money::ofNullable($r->consumption),
                type: $r->reading_type,
                unit: (string) $r->unit,
                meterNumber: (string) $r->meter_number,
                previous: Money::ofNullable($r->previous),
                current: Money::ofNullable($r->current),
            ))->values()->all(),
            charges: $bill->charges->map(fn (Charge $c) => new ChargeLine(
                service: $c->service,
                description: $c->description,
                amount: Money::of($c->amount),
                quantity: Money::ofNullable($c->quantity),
            ))->values()->all(),
        );
    }

    public function context(Bill $bill): CheckContext
    {
        $property = $bill->property ?? throw new LogicException('A bill needs a property before it can be checked.');
        $area = trim((string) $property->suburb);

        $history = Bill::query()
            ->where('property_id', $property->id)
            ->whereKeyNot($bill->id)
            ->where('status', BillStatus::Checked)
            ->whereNotNull('bill_date')
            ->when($bill->bill_date, fn (Builder $q) => $q->where('bill_date', '<', $bill->bill_date))
            ->orderByDesc('bill_date')
            ->limit(Usage::HISTORY_WINDOW)
            ->with(['readings', 'charges'])
            ->get()
            ->map(fn (Bill $past) => self::snapshot($past))
            ->all();

        $outagesTracked = $property->municipality_id !== null
            && Outage::query()->where('municipality_id', $property->municipality_id)->exists();

        $outages = $area === '' || ! $outagesTracked ? [] : Outage::query()
            ->where('municipality_id', $property->municipality_id)
            ->whereRaw('LOWER(suburb) = ?', [mb_strtolower($area)])
            ->get()
            ->map(fn (Outage $o) => new OutageWindow($o->service, $o->starts_on, $o->ends_on, $o->suburb, (string) $o->source))
            ->all();

        $rates = [];

        TariffRate::query()
            ->where('municipality_id', $property->municipality_id)
            ->when($bill->bill_date, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereNull('effective_from')
                ->orWhere('effective_from', '<=', $bill->bill_date)))
            ->orderByRaw('effective_from IS NULL DESC')
            ->orderBy('effective_from')
            ->get()
            ->each(function (TariffRate $rate) use (&$rates) {
                // Later rates replace earlier ones.
                $rates[$rate->category->value.':'.$rate->service->value] = Money::of($rate->rate);
            });

        return new CheckContext(
            bill: self::snapshot($bill),
            history: $history,
            propertyType: $property->property_type,
            outageArea: $area,
            outages: $outages,
            tariffRates: $rates,
            outagesTracked: $outagesTracked,
        );
    }

    /**
     * Check a bill and replace its findings, keeping the customer's earlier
     * answers to the same questions.
     */
    public function run(Bill $bill): CheckReport
    {
        $bill->loadMissing(['readings', 'charges', 'property.municipality', 'findings']);
        $report = $this->auditor->audit($this->context($bill));

        DB::transaction(function () use ($bill, $report) {
            $earlier = $bill->findings->keyBy(fn (Finding $f) => self::key($f->check_type, $f->service?->value, $f->data ?? []));
            $bill->findings()->delete();

            foreach ($report->findings as $index => $finding) {
                $previous = $earlier->get(self::key($finding->check, $finding->service?->value, $finding->data));

                $bill->findings()->create([
                    'number' => $index + 1,
                    'check_type' => $finding->check,
                    'service' => $finding->service,
                    'confidence' => $finding->confidence,
                    'state' => $previous?->state ?? FindingState::Open,
                    'title' => $finding->title,
                    'explanation' => $finding->explanation,
                    'amount' => $finding->amount !== null ? (string) $finding->amount : null,
                    'amount_is_upper_bound' => $finding->amountIsUpperBound,
                    'evidence' => $finding->evidence,
                    'calculation' => $finding->calculation !== '' ? $finding->calculation : null,
                    'question' => $finding->question !== '' ? $finding->question : null,
                    'answer_options' => $finding->answerOptions !== [] ? $finding->answerOptions : null,
                    'answer' => $previous?->answer,
                    'answered_at' => $previous?->answered_at,
                    'action' => $finding->action !== '' ? $finding->action : null,
                    'data' => $finding->data,
                ]);
            }

            $bill->forceFill([
                'status' => BillStatus::Checked,
                'passed' => $report->passed,
                'skipped' => $report->skipped,
                'checked_at' => now(),
            ])->save();
        });

        $bill->unsetRelation('findings');
        $this->credits->inspect($bill);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function key(CheckType $check, ?string $service, array $data): string
    {
        return implode(':', [$check->value, $service ?? '', $data['starts_on'] ?? '']);
    }
}
