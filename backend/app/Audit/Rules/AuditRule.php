<?php

namespace App\Audit\Rules;

use App\Audit\AuditContext;
use App\Audit\FindingData;
use App\Enums\FindingRule;
use App\Models\Bill;
use App\Models\Finding;

/**
 * One audit rule (docs/audit-rules.md). Rules prefer missing a problem over
 * raising a false alarm: when data is missing or ambiguous they stay silent
 * or emit a low finding.
 */
interface AuditRule
{
    public function rule(): FindingRule;

    /**
     * @return list<FindingData>
     */
    public function evaluate(AuditContext $context): array;

    /**
     * A first-person paragraph describing the finding for a dispute letter
     * to the municipality.
     */
    public function letterParagraph(Finding $finding, Bill $bill): string;
}
