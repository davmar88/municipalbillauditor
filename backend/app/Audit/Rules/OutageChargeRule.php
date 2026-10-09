<?php

namespace App\Audit\Rules;

use App\Audit\AuditContext;
use App\Audit\FindingData;
use App\Audit\ServiceUsage;
use App\Audit\Stats;
use App\Enums\FindingRule;
use App\Enums\Service;
use App\Enums\Severity;
use App\Models\Bill;
use App\Models\Finding;
use App\Models\Outage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A recorded water/electricity outage overlaps the billing period but the
 * same service still has a (possibly too high) charge.
 */
final class OutageChargeRule extends BaseRule
{
    private const SECONDS_PER_DAY = 86400;

    public function rule(): FindingRule
    {
        return FindingRule::OutageCharge;
    }

    public function evaluate(AuditContext $context): array
    {
        $bill = $context->bill;
        if ($bill->period_start === null || $bill->period_end === null || $bill->period_end->lt($bill->period_start)) {
            return [];
        }

        $config = config('audit.outage_charge');
        [$periodStart, $periodEnd] = $this->periodWindow($bill);
        $periodSeconds = $periodEnd - $periodStart;
        if ($periodSeconds <= 0) {
            return [];
        }

        $findings = [];
        foreach ($config['services'] as $serviceValue) {
            $service = Service::from($serviceValue);
            $usage = $context->usage($service);
            if ($usage->amountCents <= 0) {
                continue;
            }

            $outageSeconds = $this->overlapSeconds($context->outagesFor($service), $periodStart, $periodEnd);
            $fraction = $outageSeconds / $periodSeconds;
            if ($fraction < (float) $config['min_fraction']) {
                continue;
            }

            $finding = $this->assess($context, $service, $usage, $fraction, $outageSeconds, $periodSeconds, $config);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assess(AuditContext $context, Service $service, ServiceUsage $usage, float $fraction, int $outageSeconds, int $periodSeconds, array $config): ?FindingData
    {
        $consumption = $usage->consumption;
        $outageDays = round($outageSeconds / self::SECONDS_PER_DAY, 1);
        $periodDays = (int) round($periodSeconds / self::SECONDS_PER_DAY);

        $evidence = fn (?float $expected) => [
            'service' => $service->value,
            'outage_days' => $outageDays,
            'period_days' => $periodDays,
            'outage_fraction' => round($fraction, 3),
            'consumption' => $this->round3($consumption),
            'expected_consumption' => $this->round3($expected),
        ];

        $name = $service->label();
        $unit = $usage->unit;
        $outageText = 'You recorded a '.$name.' outage covering about '.$this->days($outageDays).' of the '.$periodDays.'-day billing period ('.$this->percent($fraction).' of it).';

        // Supply off for (practically) the whole period, yet usage was billed.
        if ($fraction >= (float) $config['full_period_fraction'] && $consumption !== null && $consumption > 0) {
            return new FindingData(
                rule: $this->rule(),
                service: $service,
                severity: Severity::from($config['full_period']['severity']),
                confidence: (float) $config['full_period']['confidence'],
                title: 'You may have been charged for '.$name.' during an outage',
                explanation: $this->sentences([
                    $outageText,
                    'That looks like the whole period, but this bill still charges '.$this->rand($usage->consumptionAmountCents).' for '.$this->qty($consumption, $unit).' of '.$name.'.',
                    'If the supply really was off, you may have been overcharged by that amount.',
                    'Read your meter and compare it with the reading on the bill, and check that the outage dates you recorded are right.',
                ]),
                estimatedOverchargeCents: $usage->consumptionAmountCents,
                evidence: $evidence(0.0),
                lineItemId: $usage->primaryLineItemId,
            );
        }

        $history = $context->historyWithUsage($service, (int) $config['max_history']);
        if ($consumption !== null && $history->count() >= (int) $config['min_history']) {
            $median = (float) Stats::median($history->map(fn (ServiceUsage $u) => $u->consumption));
            $expected = $median * (1 - $fraction);

            if ($consumption <= $expected * (1 + (float) $config['tolerance'])) {
                return null;
            }

            $rate = $usage->centsPerUnit();
            $overcharge = $rate === null ? null : (int) round(($consumption - $expected) * $rate);

            return new FindingData(
                rule: $this->rule(),
                service: $service,
                severity: Severity::from($config['over_expected']['severity']),
                confidence: (float) $config['over_expected']['confidence'],
                title: ucfirst($name).' use looks high for a period with an outage',
                explanation: $this->sentences([
                    $outageText,
                    'Based on your usual use of about '.$this->qty($median, $unit).' per bill, we\'d expect roughly '.$this->qty($expected, $unit).' for this period, but the bill charges for '.$this->qty($consumption, $unit).'.',
                    $overcharge !== null && $overcharge > 0 ? 'You may have been overcharged by about '.$this->rand($overcharge).'.' : null,
                    'Read your meter and compare it with the reading on the bill, and check that the outage dates you recorded are right.',
                ]),
                estimatedOverchargeCents: $overcharge,
                evidence: $evidence($expected),
                lineItemId: $usage->primaryLineItemId,
            );
        }

        return new FindingData(
            rule: $this->rule(),
            service: $service,
            severity: Severity::from($config['no_history']['severity']),
            confidence: (float) $config['no_history']['confidence'],
            title: 'Check your '.$name.' charge for the outage period',
            explanation: $this->sentences([
                $outageText,
                'The bill still has '.$name.' charges of '.$this->rand($usage->amountCents).'.',
                'We don\'t have enough earlier bills to work out what you\'d normally use, so we can\'t tell whether the charge is too high.',
                'Read your meter and compare it with the reading on the bill.',
            ]),
            estimatedOverchargeCents: null,
            evidence: $evidence(null),
            lineItemId: $usage->primaryLineItemId,
        );
    }

    /**
     * The billing period as [start, end) Unix timestamps: from the start of
     * period_start to the end of period_end, in the audit timezone.
     *
     * @return array{0: int, 1: int}
     */
    private function periodWindow(Bill $bill): array
    {
        $timezone = config('audit.timezone', 'UTC');
        $start = CarbonImmutable::parse($bill->period_start->toDateString(), $timezone)->startOfDay();
        $end = CarbonImmutable::parse($bill->period_end->toDateString(), $timezone)->addDay()->startOfDay();

        return [$start->getTimestamp(), $end->getTimestamp()];
    }

    /**
     * Total outage time inside the window, merging overlapping outages.
     *
     * @param  Collection<int, Outage>  $outages
     */
    private function overlapSeconds(Collection $outages, int $windowStart, int $windowEnd): int
    {
        $intervals = [];
        foreach ($outages as $outage) {
            $start = max($outage->starts_at->getTimestamp(), $windowStart);
            $end = min($outage->ends_at->getTimestamp(), $windowEnd);
            if ($end > $start) {
                $intervals[] = [$start, $end];
            }
        }

        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $total = 0;
        $current = null;
        foreach ($intervals as [$start, $end]) {
            if ($current === null) {
                $current = [$start, $end];
            } elseif ($start <= $current[1]) {
                $current[1] = max($current[1], $end);
            } else {
                $total += $current[1] - $current[0];
                $current = [$start, $end];
            }
        }
        if ($current !== null) {
            $total += $current[1] - $current[0];
        }

        return $total;
    }

    private function days(float $days): string
    {
        return $this->times($days).($days == 1.0 ? ' day' : ' days');
    }

    public function letterParagraph(Finding $finding, Bill $bill): string
    {
        $service = $finding->service ?? Service::Water;
        $evidence = $finding->evidence;
        $unit = $service->defaultUnit();
        $name = $service->label();

        $text = 'There was a '.$name.' outage at the property lasting about '.$this->days((float) ($evidence['outage_days'] ?? 0))
            .' of the '.($evidence['period_days'] ?? '?').'-day billing period, yet this bill charges '
            .$this->rand($this->serviceAmountCents($bill, $service)).' for '.$name;
        $text .= isset($evidence['consumption']) ? ' ('.$this->qty((float) $evidence['consumption'], $unit).').' : '.';

        if (isset($evidence['expected_consumption']) && (float) $evidence['expected_consumption'] > 0) {
            $text .= ' Given the outage, I would expect about '.$this->qty((float) $evidence['expected_consumption'], $unit).'.';
        } elseif (isset($evidence['expected_consumption'])) {
            $text .= ' No '.$name.' could have been used while the supply was off.';
        }

        return $text.' I ask that the charge be corrected for the outage period.';
    }
}
