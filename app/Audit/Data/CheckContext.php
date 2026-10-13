<?php

namespace App\Audit\Data;

use App\Audit\Enums\PropertyType;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use Brick\Math\BigDecimal;

/**
 * Everything the checks need to judge one bill.
 */
final readonly class CheckContext
{
    /**
     * @param  list<BillSnapshot>  $history  Earlier bills for the same property, newest first.
     * @param  string  $outageArea  The suburb outages were looked up for. Empty means we couldn't look.
     * @param  list<OutageWindow>  $outages  Outages reported for that area. The check finds the overlap.
     * @param  array<string, BigDecimal>  $tariffRates  Rate per unit, keyed "category:service".
     * @param  bool  $outagesTracked  Whether we keep outage reports for this municipality at all.
     */
    public function __construct(
        public BillSnapshot $bill,
        public array $history = [],
        public PropertyType $propertyType = PropertyType::Unknown,
        public string $outageArea = '',
        public array $outages = [],
        public array $tariffRates = [],
        public bool $outagesTracked = true,
    ) {}

    public function rate(TariffCategory $category, Service $service): ?BigDecimal
    {
        return $this->tariffRates[$category->value.':'.$service->value] ?? null;
    }
}
