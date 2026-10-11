<?php

namespace App\Audit\Checks;

use App\Audit\Data\CheckContext;
use App\Audit\Data\CheckReport;
use App\Audit\Data\Finding;
use App\Audit\Dates;
use App\Audit\Enums\CheckType;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\ReadingType;
use App\Audit\Money;

/**
 * Outages reported for the property's suburb during the billing period.
 *
 * An actual reading only counts what flowed through the meter, so an outage
 * matters when the reading was estimated: the estimate charges for days
 * without supply. The customer confirms the outage reached them before it
 * goes into a dispute.
 */
final class OutageCheck implements Check
{
    public function run(CheckContext $ctx): CheckReport
    {
        $report = new CheckReport;
        $bill = $ctx->bill;
        $area = $ctx->outageArea;

        if ($area === '') {
            $report->skipped[] = 'Add your suburb so we can check for outages.';

            return $report;
        }

        $periodDays = $bill->periodDays();

        if ($periodDays === null) {
            $report->skipped[] = "We couldn't read the billing period, so we couldn't check for outages.";

            return $report;
        }

        $overlapping = false;

        foreach ($ctx->outages as $outage) {
            $first = Dates::max($bill->periodStart, $outage->startsOn);
            $last = Dates::min($bill->periodEnd, $outage->endsOn);

            if ($last < $first) {
                continue;
            }

            $overlapping = true;
            $usage = Usage::of($bill, $outage->service);

            if ($usage === null) {
                continue;
            }

            [$used, $kind] = $usage;
            $service = $outage->service;
            $name = strtolower($service->label());
            $unit = $bill->readingsFor($service)[0]->unitLabel();
            $days = Dates::daysBetween($first, $last) + 1;
            $when = Dates::short($outage->startsOn).' to '.Dates::short($outage->endsOn);
            $place = $outage->area !== '' ? $outage->area : $area;

            if ($kind === ReadingType::Actual) {
                $report->passed[] = $service->label()." was off from {$when}, but the reading is actual, "
                    ."so you weren't charged for {$name} you didn't get.";

                continue;
            }

            $usual = Usage::usual($ctx, $service);
            $basis = $usual[0] ?? $used;
            $rate = Usage::rate($bill, $service, $used);
            $amount = null;
            $calculation = '';
            $evidence = [['Outage reported', "{$when}, {$place}"]];

            if ($outage->source !== '') {
                $evidence[] = ['Source', $outage->source];
            }

            $evidence[] = ['Days billed without supply', "{$days} of {$periodDays}"];

            if ($basis !== null) {
                $evidence[] = [$usual !== null ? 'Your usual use' : 'Billed use', Money::quantity($basis)." {$unit} a month"];
            }

            if ($basis !== null && $rate !== null) {
                // Multiply first and divide once, so the shown sum gives the shown answer.
                $amount = Money::divide($basis->multipliedBy($days)->multipliedBy($rate), $periodDays);
                $evidence[] = ['Rate on this bill', Money::format($rate)." per {$unit}"];
                $calculation = Money::quantity($basis)." {$unit} ÷ {$periodDays} days × {$days} days × "
                    .Money::format($rate).' = '.Money::format($amount);
            }

            $report->findings[] = new Finding(
                check: CheckType::OutageCharge,
                service: $service,
                confidence: Confidence::Question,
                title: "Billed for {$name} while it was off?",
                explanation: "An outage was reported in {$place} from {$when}. The reading was estimated, "
                    ."so the bill charges for {$days} days without supply.",
                amount: $amount,
                evidence: $evidence,
                calculation: $calculation,
                question: "Was the {$name} off at the property from {$when}?",
                answerOptions: [['yes', 'Yes, it was off'], ['no', 'No'], ['not_sure', 'Not sure']],
                data: [
                    'starts_on' => $outage->startsOn->format('Y-m-d'),
                    'ends_on' => $outage->endsOn->format('Y-m-d'),
                    'days' => $days,
                    'period_days' => $periodDays,
                    'basis' => $basis !== null ? (string) $basis : null,
                    'rate' => $rate !== null ? (string) $rate : null,
                    'unit' => $unit,
                    'area' => $place,
                ],
            );
        }

        if (! $overlapping) {
            $report->passed[] = "No outages were reported in {$area} during this billing period.";
        }

        return $report;
    }
}
