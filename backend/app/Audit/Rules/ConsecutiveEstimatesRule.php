<?php

namespace App\Audit\Rules;

use App\Audit\AuditContext;
use App\Audit\FindingData;
use App\Enums\FindingRule;
use App\Enums\ReadingType;
use App\Enums\Service;
use App\Enums\Severity;
use App\Models\Bill;
use App\Models\Finding;
use App\Models\LineItem;

/**
 * This bill and the bills immediately before it were all estimated for the
 * same service. Emitted once per service, on the newest bill of the run
 * only: when the next bill is estimated too, the finding moves there.
 */
final class ConsecutiveEstimatesRule extends BaseRule
{
    public function rule(): FindingRule
    {
        return FindingRule::ConsecutiveEstimates;
    }

    public function evaluate(AuditContext $context): array
    {
        $config = config('audit.consecutive_estimates');
        $findings = [];

        foreach ($this->meteredServices() as $service) {
            if (! $this->isEstimated($context->bill, $service)) {
                continue;
            }
            if ($context->next !== null && $this->isEstimated($context->next, $service)) {
                // Not the newest bill of the run.
                continue;
            }

            $run = [$context->bill->id];
            foreach ($context->history as $previous) {
                if (! $this->isEstimated($previous, $service)) {
                    break;
                }
                $run[] = $previous->id;
            }

            $count = count($run);
            if ($count < (int) $config['min_consecutive']) {
                continue;
            }

            $name = $service->label();
            $findings[] = new FindingData(
                rule: $this->rule(),
                service: $service,
                severity: Severity::from($config['severity']),
                confidence: (float) $config['confidence'],
                title: ucfirst($name)." has been estimated $count bills in a row",
                explanation: $this->sentences([
                    "Your $name has been billed on estimated readings for $count bills in a row, including this one.",
                    'Municipalities should read meters regularly. Long runs of estimates often end in a large catch-up bill when the meter is finally read, and the estimates may not match what you really used.',
                    'Read your meter now and keep a dated photo of it, then ask the municipality to take an actual reading and correct your account.',
                ]),
                estimatedOverchargeCents: null,
                evidence: [
                    'service' => $service->value,
                    'consecutive_count' => $count,
                    'bill_ids' => $run,
                ],
                lineItemId: $context->lineItems
                    ->first(fn (LineItem $l) => $l->service === $service && $l->reading_type === ReadingType::Estimated)?->id,
            );
        }

        return $findings;
    }

    private function isEstimated(Bill $bill, Service $service): bool
    {
        return $bill->lineItems->contains(
            fn (LineItem $l) => $l->service === $service && $l->reading_type === ReadingType::Estimated,
        );
    }

    public function letterParagraph(Finding $finding, Bill $bill): string
    {
        $service = $finding->service ?? Service::Water;
        $count = (int) ($finding->evidence['consecutive_count'] ?? 3);

        return 'My '.$service->label()." has been billed on estimated readings for $count consecutive bills, including this one."
            .' I ask that an actual meter reading be taken and that the account be recalculated on my actual consumption.';
    }
}
