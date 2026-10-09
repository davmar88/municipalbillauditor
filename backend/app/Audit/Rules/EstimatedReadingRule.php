<?php

namespace App\Audit\Rules;

use App\Audit\AuditContext;
use App\Audit\FindingData;
use App\Audit\ServiceUsage;
use App\Audit\Stats;
use App\Enums\FindingRule;
use App\Enums\ReadingType;
use App\Enums\Service;
use App\Enums\Severity;
use App\Models\Bill;
use App\Models\Finding;

/**
 * A water or electricity charge based on an estimated reading.
 */
final class EstimatedReadingRule extends BaseRule
{
    public function rule(): FindingRule
    {
        return FindingRule::EstimatedReading;
    }

    public function evaluate(AuditContext $context): array
    {
        $config = config('audit.estimated_reading');
        $findings = [];

        foreach ($this->meteredServices() as $service) {
            $estimated = $context->usage($service, ReadingType::Estimated);
            if (! $estimated->hasLines()) {
                continue;
            }

            $actuals = $context->historyUsages($service)
                ->filter(fn (ServiceUsage $u) => $u->readingType === ReadingType::Actual && $u->hasUsage())
                ->take((int) $config['max_actual_compared'])
                ->values();

            $median = Stats::median($actuals->map(fn (ServiceUsage $u) => $u->consumption));
            $usage = $estimated->consumption;

            $severity = Severity::Low;
            if ($usage !== null && $median !== null && $usage > $median * (1 + (float) $config['medium_above_median_ratio'])) {
                $severity = Severity::Medium;
            }

            $overcharge = null;
            $rate = $estimated->centsPerUnit();
            if ($usage !== null && $median !== null && $rate !== null
                && $actuals->count() >= (int) $config['min_actual_for_overcharge']) {
                $overcharge = (int) round(max(0, $usage - $median) * $rate);
            }

            $findings[] = new FindingData(
                rule: $this->rule(),
                service: $service,
                severity: $severity,
                confidence: (float) $config['confidence'],
                title: 'Your '.$service->label().' reading was estimated',
                explanation: $this->explanation($service, $estimated, $median, $actuals->count(), $severity, $overcharge),
                estimatedOverchargeCents: $overcharge,
                evidence: [
                    'service' => $service->value,
                    'consumption' => $this->round3($usage),
                    'median_actual_consumption' => $this->round3($median),
                    'actual_bills_compared' => $actuals->count(),
                ],
                lineItemId: $estimated->primaryLineItemId,
            );
        }

        return $findings;
    }

    private function explanation(Service $service, ServiceUsage $estimated, ?float $median, int $compared, Severity $severity, ?int $overcharge): string
    {
        $name = $service->label();
        $unit = $estimated->unit;
        $usage = $estimated->consumption;

        $comparison = null;
        if ($usage !== null && $median !== null) {
            $readings = $compared === 1 ? 'your last actual reading' : "your last $compared actual readings";
            $comparison = 'The estimate is '.$this->qty($usage, $unit).', while '.$readings.' came to about '
                .$this->qty($median, $unit).($compared === 1 ? '.' : ' (the middle value).');
            if ($severity === Severity::Medium && $median > 0) {
                $ratio = $usage / $median;
                $comparison .= $ratio >= 2
                    ? ' That is about '.$this->times(round($ratio, 1)).' times what you usually use, so the estimate may be too high.'
                    : ' That is about '.$this->percent($ratio - 1).' more than usual, so the estimate may be too high.';
            }
        } elseif ($median === null) {
            $comparison = "We don't have earlier actual readings for your $name to compare it with yet.";
        }

        $money = null;
        if ($overcharge !== null && $overcharge > 0) {
            $money = 'If your real use was close to normal, you may have been overcharged by about '.$this->rand($overcharge).'.';
        }

        return $this->sentences([
            'Your '.$name.' charge of '.$this->rand($estimated->amountCents).' on this bill is based on an estimated meter reading, not an actual one.',
            $comparison,
            $money,
            'Read your meter yourself and compare it with the reading on the bill.',
            'Municipalities should bill on actual readings where they reasonably can, and repeated estimates often lead to a large catch-up bill later, so you can ask them to take an actual reading and correct the account.',
        ]);
    }

    public function letterParagraph(Finding $finding, Bill $bill): string
    {
        $service = $finding->service ?? Service::Water;
        $evidence = $finding->evidence;
        $unit = $service->defaultUnit();

        $text = 'The '.$service->label().' charge of '.$this->rand($this->serviceAmountCents($bill, $service))
            .' on this bill is based on an estimated meter reading';
        $text .= isset($evidence['consumption'])
            ? ' of '.$this->qty((float) $evidence['consumption'], $unit).', not an actual reading.'
            : ', not an actual reading.';

        if (isset($evidence['median_actual_consumption'])) {
            $text .= ' My recent actual readings came to about '.$this->qty((float) $evidence['median_actual_consumption'], $unit).' per bill.';
        }

        return $text.' I ask that the charge be recalculated on an actual reading.';
    }
}
