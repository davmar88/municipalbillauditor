<?php

namespace App\Audit;

use App\Enums\FindingRule;
use App\Enums\Service;
use App\Enums\Severity;

/**
 * A finding produced by a rule, before it is stored.
 */
final class FindingData
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly FindingRule $rule,
        public readonly ?Service $service,
        public readonly Severity $severity,
        public readonly float $confidence,
        public readonly string $title,
        public readonly string $explanation,
        public readonly ?int $estimatedOverchargeCents,
        public readonly array $evidence,
        public readonly ?int $lineItemId = null,
    ) {}

    /**
     * Identifies "the same finding" across re-audits: same rule + same
     * service (line items are replaced on edit, so their ids change).
     */
    public function matchKey(): string
    {
        return $this->rule->value.':'.($this->service?->value ?? 'bill');
    }
}
