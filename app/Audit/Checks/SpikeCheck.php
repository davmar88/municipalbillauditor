<?php

namespace App\Audit\Checks;

use App\Audit\Data\CheckContext;
use App\Audit\Data\CheckReport;
use App\Audit\Data\Finding;
use App\Audit\Enums\CheckType;
use App\Audit\Enums\Confidence;
use App\Audit\Enums\ReadingType;
use App\Audit\Money;

/**
 * An actual reading far above the property's own average.
 *
 * A real reading can still be wrong (a faulty meter) or right (a leak, more
 * people living there), so this asks before it is treated as an error.
 * Estimated readings are left to the estimated-reading check.
 */
final class SpikeCheck implements Check
{
    /** A spike is at least this many times the average. */
    public const RATIO = '2';

    /** Bills needed before an average means anything. */
    public const MIN_HISTORY = 3;

    public function run(CheckContext $ctx): CheckReport
    {
        $report = new CheckReport;

        foreach ($ctx->bill->readings as $reading) {
            if (! $reading->service->isMetered() || $reading->type === ReadingType::Estimated) {
                continue;
            }

            $used = $reading->used();

            if ($used === null) {
                continue;
            }

            $service = $reading->service;
            $label = $service->label();
            $unit = $reading->unitLabel();
            $usual = Usage::usual($ctx, $service, $reading->meterNumber, self::MIN_HISTORY);

            if ($usual === null) {
                $report->skipped[] = 'We need at least '.self::MIN_HISTORY.' past bills to compare your '
                    .strtolower($label)." use. We'll check it as more bills come in.";

                continue;
            }

            [$average, $billsUsed] = $usual;
            $excess = $used->minus($average);

            if (! $average->isPositive()
                || $used->isLessThan($average->multipliedBy(self::RATIO))
                || $excess->isLessThan($service->minimumExcess())) {
                $report->passed[] = 'No unusual jump in '.strtolower($label).' use compared with your past bills.';

                continue;
            }

            $ratio = Money::divide($used, $average, 1);
            $rate = Usage::rate($ctx->bill, $service, $used);
            $amount = $rate !== null ? Money::money($excess->multipliedBy($rate)) : null;
            $evidence = [
                ['This bill', Money::quantity($used)." {$unit}, actual reading"],
                ['Your average', Money::quantity($average)." {$unit} over {$billsUsed} bills"],
            ];
            $calculation = '';

            if ($rate !== null) {
                $evidence[] = ['Rate on this bill', Money::format($rate)." per {$unit}"];
                $calculation = '('.Money::quantity($used).' − '.Money::quantity($average).") {$unit} × "
                    .Money::format($rate).' = '.Money::format($amount);
            }

            $report->findings[] = new Finding(
                check: CheckType::UsageSpike,
                service: $service,
                confidence: Confidence::Possible,
                title: "{$label} is {$ratio} times your average",
                explanation: 'An actual reading of '.Money::quantity($used)." {$unit} against an average of "
                    .Money::quantity($average)." {$unit}. It could be a faulty meter, a leak or a real change in use.",
                amount: $amount,
                amountIsUpperBound: true,
                evidence: $evidence,
                calculation: $calculation,
                question: 'Did anything change at this property this month?',
                answerOptions: [['expected', "Yes, it's expected"], ['keep', 'No change, keep the flag']],
                data: [
                    'billed' => (string) $used,
                    'average' => (string) $average,
                    'ratio' => (string) $ratio,
                    'unit' => $unit,
                    'rate' => $rate !== null ? (string) $rate : null,
                ],
            );
        }

        return $report;
    }
}
