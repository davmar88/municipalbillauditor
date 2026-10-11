<?php

namespace App\Audit\Fees;

use Brick\Math\BigDecimal;

final readonly class SuccessFee
{
    public function __construct(
        public BigDecimal $rate,
        public BigDecimal $backRefund,
        public BigDecimal $backRefundFee,
        public BigDecimal $monthlySaving,
        public int $savingMonths,
        public BigDecimal $savingsTotal,
        public BigDecimal $monthlySavingFee,
        public BigDecimal $savingsFeeTotal,
        public BigDecimal $totalWon,
        public BigDecimal $totalFee,
    ) {}
}
