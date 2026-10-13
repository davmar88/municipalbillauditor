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
use App\Audit\Text;
use Brick\Math\BigDecimal;

/**
 * A reading marked as estimated, compared with the property's usual use.
 */
final class EstimatedReadingCheck implements Check
{
    /** An estimate is only flagged when it is this share above usual use. */
    public const TOLERANCE = '0.25';

    public function run(CheckContext $ctx): CheckReport
    {
        $report = new CheckReport;

        foreach ($ctx->bill->readings as $reading) {
            if (! $reading->service->isMetered() || $reading->type !== ReadingType::Estimated) {
                continue;
            }

            $service = $reading->service;
            $name = strtolower($service->label());
            $unit = $reading->unitLabel();
            $billed = $reading->used();

            if ($billed === null) {
                $report->skipped[] = "Your {$name} reading was estimated, but we couldn't read how much it charges for.";

                continue;
            }

            $streak = Usage::estimateStreak($ctx, $service, $reading->meterNumber);
            $title = "Your {$name} reading was estimated";

            if (count($streak) >= 2) {
                $title .= ' for the '.Text::ordinal(count($streak)).' month running';
            }

            $context = [];

            if (count($streak) >= 2) {
                $months = array_map(fn ($bill) => $bill->month(), array_reverse($streak));
                $context[] = in_array(null, $months, true)
                    ? ['Estimated bills in a row', (string) count($streak)]
                    : ['Estimated bills in a row', Text::join(array_map(fn ($month) => $month->format('M'), $months))];
            }

            $lastActual = Usage::lastActual($ctx, $service, $reading->meterNumber);

            if ($lastActual !== null && $lastActual[0] !== null) {
                [$readOn, $meterValue] = $lastActual;
                $context[] = ['Last actual reading', $meterValue !== null
                    ? Money::quantity($meterValue).' on '.Dates::long($readOn)
                    : Dates::long($readOn)];
            }

            $evidence = [['Billed', Money::quantity($billed)." {$unit}, estimated"]];
            $data = [
                'billed' => (string) $billed,
                'unit' => $unit,
                'streak' => count($streak),
                'meter_number' => $reading->meterNumber,
            ];

            $usual = Usage::usual($ctx, $service, $reading->meterNumber);

            if ($usual === null) {
                $report->findings[] = new Finding(
                    check: CheckType::EstimatedReading,
                    service: $service,
                    confidence: Confidence::Possible,
                    title: $title,
                    explanation: "The bill marks the reading as estimated. We don't have enough of your past bills "
                        .'to tell whether the estimate is too high. A dated photo of your meter shows the real reading.',
                    evidence: [...$evidence, ...$context],
                    action: 'meter_photo',
                    data: $data,
                );

                continue;
            }

            [$usualUse, $readingsUsed] = $usual;
            $excess = $billed->minus($usualUse);
            $limit = $usualUse->multipliedBy(BigDecimal::one()->plus(self::TOLERANCE));

            if ($billed->isLessThanOrEqualTo($limit) || $excess->isLessThan($service->minimumExcess())) {
                $report->passed[] = "Your {$name} reading was estimated, but the estimate is in line with your usual use.";

                continue;
            }

            $rate = Usage::rate($ctx->bill, $service, $billed);
            $amount = $rate !== null ? Money::money($excess->multipliedBy($rate)) : null;
            $comparison = $billed->isGreaterThan($usualUse->multipliedBy(2)) ? 'more than double' : 'well above';

            $explanation = 'The bill marks the reading as estimated (E). It assumes '.Money::quantity($billed)." {$unit}, "
                ."{$comparison} your usual ".Money::quantity($usualUse)." {$unit}.";

            if ($rate === null) {
                $explanation .= " We couldn't find the {$name} charge on the bill, so we can't put a rand amount on it yet.";
            }

            $evidence[] = ['Your usual use', Money::quantity($usualUse)." {$unit} a month, from {$readingsUsed} actual readings"];
            array_push($evidence, ...$context);
            $calculation = '';

            if ($rate !== null) {
                $evidence[] = ['Rate on this bill', Money::format($rate)." per {$unit}"];
                $calculation = '('.Money::quantity($billed).' − '.Money::quantity($usualUse).") {$unit} × "
                    .Money::format($rate).' = '.Money::format($amount);
            }

            $report->findings[] = new Finding(
                check: CheckType::EstimatedReading,
                service: $service,
                confidence: Confidence::Likely,
                title: $title,
                explanation: $explanation,
                amount: $amount,
                evidence: $evidence,
                calculation: $calculation,
                action: 'meter_photo',
                data: [
                    ...$data,
                    'usual' => (string) $usualUse,
                    'excess' => (string) $excess,
                    'rate' => $rate !== null ? (string) $rate : null,
                ],
            );
        }

        return $report;
    }
}
