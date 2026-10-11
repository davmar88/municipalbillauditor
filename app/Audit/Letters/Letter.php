<?php

namespace App\Audit\Letters;

use Brick\Math\BigDecimal;

final readonly class Letter
{
    public function __construct(
        public string $subject,
        public string $body,
        public BigDecimal $disputedTotal,
        public ?BigDecimal $undisputedAmount,
    ) {}
}
