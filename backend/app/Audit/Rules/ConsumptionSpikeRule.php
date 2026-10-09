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
 * Usage far above the median of previous bills. A spike can be a real leak,
 * which is still worth telling the customer about.
 */
final class ConsumptionSpikeRule extends BaseRule
{
    public function rule(): FindingRule
    {
        return FindingRule::ConsumptionSpike;
    }

    public function evaluate(AuditContext $context): array
    {
        $config = config('audit.consumption_spike');
        $findings = [];

        foreach ($this->meteredServices() as $service) {
            $usage = $context->usage($service);
            $consumption = $usage->consumption;

            // Estimated readings are covered by the estimated_reading rule.
            if ($consumption === null || $consumption <= 0 || $usage->readingType === ReadingType::Estimated) {
                continue;
            }

            $history = $context->historyWithUsage($service, (int) $config['max_history']);
            if ($history->count() < (int) $config['min_history']) {
                continue;
            }

            $median = (float) Stats::median($history->map(fn (ServiceUsage $u) => $u->consumption));
            $floor = (float) ($config['absolute_floor'][$service->value] ?? 0);

            if (! ($consumption > (float) $config['ratio'] * $median && $consumption - $median > $floor)) {
                continue;
            }

            $severity = $consumption > (float) $config['high_ratio'] * $median ? Severity::High : Severity::Medium;
            $rate = $usage->centsPerUnit();
            $overcharge = $rate === null ? null : (int) round(($consumption - $median) * $rate);
            $ratio = $median > 0 ? round($consumption / $median, 1) : null;

            $name = $service->label();
            $unit = $usage->unit;
            $title = $ratio !== null
                ? ucfirst($name).' use is '.$this->times($ratio).' times your usual'
                : ucfirst($name).' use is much higher than usual';

            $findings[] = new FindingData(
                rule: $this->rule(),
                service: $service,
                severity: $severity,
                confidence: (float) $config['confidence'],
                title: $title,
                explanation: $this->sentences([
                    'This bill charges for '.$this->qty($consumption, $unit).' of '.$name
                        .($ratio !== null ? ', about '.$this->times($ratio).' times' : ', far more than')
                        .' your usual '.$this->qty($median, $unit).' (the middle value of your last '.$history->count().' bills).',
                    $service === Service::Water
                        ? 'This could be a billing mistake, such as a wrong or misread meter reading, but it could also be a real leak or a change in how much water you\'ve used.'
                        : 'This could be a billing mistake, such as a wrong or misread meter reading, but it could also be real, for example a faulty geyser or appliance, or a change in how much power you\'ve used.',
                    'Read your meter and compare it with the reading on this bill.',
                    $overcharge !== null && $overcharge > 0
                        ? 'If your meter shows less, the bill may be wrong and you may have been overcharged by about '.$this->rand($overcharge).'.'
                        : 'If your meter shows less, the bill may be wrong.',
                    $service === Service::Water
                        ? 'If the reading matches, check for leaks, for example a running toilet or a dripping tap or geyser overflow.'
                        : 'If the reading matches, check whether a geyser, pool pump or other appliance is using more power than usual.',
                ]),
                estimatedOverchargeCents: $overcharge,
                evidence: [
                    'service' => $service->value,
                    'consumption' => $this->round3($consumption),
                    'median_consumption' => $this->round3($median),
                    'bills_compared' => $history->count(),
                    'ratio' => $ratio,
                ],
                lineItemId: $usage->primaryLineItemId,
            );
        }

        return $findings;
    }

    public function letterParagraph(Finding $finding, Bill $bill): string
    {
        $service = $finding->service ?? Service::Water;
        $evidence = $finding->evidence;
        $unit = $service->defaultUnit();

        $text = 'This bill charges '.$this->rand($this->serviceAmountCents($bill, $service)).' for '
            .$this->qty((float) ($evidence['consumption'] ?? 0), $unit).' of '.$service->label();
        if (isset($evidence['ratio'], $evidence['median_consumption'])) {
            $text .= ', about '.$this->times((float) $evidence['ratio']).' times my usual consumption of '
                .$this->qty((float) $evidence['median_consumption'], $unit).' per bill over my previous '
                .($evidence['bills_compared'] ?? '').' bills';
        }

        return $text.'. I believe the meter reading may be incorrect and ask that the meter be read again and the charge corrected.';
    }
}
