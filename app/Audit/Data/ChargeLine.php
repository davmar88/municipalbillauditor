<?php

namespace App\Audit\Data;

use App\Audit\Enums\Service;
use Brick\Math\BigDecimal;

final readonly class ChargeLine
{
    public function __construct(
        public Service $service,
        public string $description,
        public BigDecimal $amount,
        public ?BigDecimal $quantity = null,
    ) {}
}
