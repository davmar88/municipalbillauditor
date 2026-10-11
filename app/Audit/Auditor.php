<?php

namespace App\Audit;

use App\Audit\Checks\Check;
use App\Audit\Checks\EstimatedReadingCheck;
use App\Audit\Checks\OutageCheck;
use App\Audit\Checks\SpikeCheck;
use App\Audit\Checks\TariffCheck;
use App\Audit\Data\CheckContext;
use App\Audit\Data\CheckReport;
use App\Audit\Data\Finding;

/**
 * Runs the four checks on one bill.
 *
 * Every rand amount a finding shows comes with a calculation built from the
 * figures shown beside it, rounded before use, so a customer can redo it.
 * The thresholds lean towards missing a small error rather than raising a
 * false alarm: a wrong flag costs a paying customer time with the municipality.
 */
final class Auditor
{
    /** @var list<Check> */
    private array $checks;

    /**
     * @param  list<Check>|null  $checks
     */
    public function __construct(?array $checks = null)
    {
        $this->checks = $checks ?? [
            new EstimatedReadingCheck,
            new OutageCheck,
            new TariffCheck,
            new SpikeCheck,
        ];
    }

    public function audit(CheckContext $ctx): CheckReport
    {
        $report = new CheckReport;

        foreach ($this->checks as $check) {
            $report->merge($check->run($ctx));
        }

        // Most certain first, then the largest amount.
        usort($report->findings, function (Finding $a, Finding $b): int {
            return $a->confidence->order() <=> $b->confidence->order()
                ?: ($b->amount ?? Money::zero())->compareTo($a->amount ?? Money::zero());
        });

        return $report;
    }
}
