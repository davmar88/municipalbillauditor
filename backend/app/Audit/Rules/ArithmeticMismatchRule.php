<?php

namespace App\Audit\Rules;

use App\Audit\AuditContext;
use App\Audit\FindingData;
use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Models\Bill;
use App\Models\Finding;

/**
 * The bill total differs from the sum of the captured line items. Low only:
 * the total may include VAT lines or adjustments that weren't captured.
 */
final class ArithmeticMismatchRule extends BaseRule
{
    public function rule(): FindingRule
    {
        return FindingRule::ArithmeticMismatch;
    }

    public function evaluate(AuditContext $context): array
    {
        $total = $context->bill->total_cents;
        if ($total === null || $context->lineItems->isEmpty()) {
            return [];
        }

        $config = config('audit.arithmetic_mismatch');
        $sum = (int) $context->lineItems->sum('amount_cents');
        $difference = $total - $sum;

        if (abs($difference) <= (int) $config['tolerance_cents']) {
            return [];
        }

        return [new FindingData(
            rule: $this->rule(),
            service: null,
            severity: Severity::from($config['severity']),
            confidence: (float) $config['confidence'],
            title: 'The bill total doesn\'t match the line items',
            explanation: $this->sentences([
                'The total of current charges on this bill is '.$this->rand($total).', but the line items add up to '.$this->rand($sum).', a difference of '.$this->rand(abs($difference)).'.',
                'This may simply be because some lines, like VAT or adjustments, weren\'t captured.',
                'Check the line items against your bill. If they all match and the total is still different, you can ask the municipality to explain the difference.',
            ]),
            estimatedOverchargeCents: null,
            evidence: [
                'total_cents' => $total,
                'line_items_sum_cents' => $sum,
                'difference_cents' => $difference,
            ],
        )];
    }

    public function letterParagraph(Finding $finding, Bill $bill): string
    {
        $evidence = $finding->evidence;

        return 'The total of current charges on this bill ('.$this->rand((int) ($evidence['total_cents'] ?? 0))
            .') does not match the sum of the individual charges ('.$this->rand((int) ($evidence['line_items_sum_cents'] ?? 0))
            .'). I ask for a breakdown that explains the difference.';
    }
}
