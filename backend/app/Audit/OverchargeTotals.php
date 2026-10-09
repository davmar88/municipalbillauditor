<?php

namespace App\Audit;

use App\Models\Finding;

/**
 * Adds up estimated overcharges without counting the same charge twice.
 *
 * Several findings can describe the same excess charge (for example an
 * estimated water reading during a water outage). Per bill and service only
 * the largest estimate is counted; bill-level findings count on their own.
 */
final class OverchargeTotals
{
    /**
     * @param  iterable<Finding>  $findings
     */
    public static function sum(iterable $findings): int
    {
        $largest = [];
        foreach ($findings as $finding) {
            $amount = max(0, (int) $finding->estimated_overcharge_cents);
            $key = $finding->bill_id.':'.($finding->service?->value ?? 'finding-'.$finding->id);
            $largest[$key] = max($largest[$key] ?? 0, $amount);
        }

        return array_sum($largest);
    }

    /**
     * Plain sum of every finding's estimate (may count a charge twice).
     *
     * @param  iterable<Finding>  $findings
     */
    public static function naiveSum(iterable $findings): int
    {
        $total = 0;
        foreach ($findings as $finding) {
            $total += max(0, (int) $finding->estimated_overcharge_cents);
        }

        return $total;
    }
}
