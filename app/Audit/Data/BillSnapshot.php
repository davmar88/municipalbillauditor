<?php

namespace App\Audit\Data;

use App\Audit\Dates;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * One bill as the engine sees it. The app builds these from stored bills.
 */
final readonly class BillSnapshot
{
    /**
     * @param  list<Reading>  $readings
     * @param  list<ChargeLine>  $charges
     */
    public function __construct(
        public ?DateTimeImmutable $billDate,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public TariffCategory $tariff = TariffCategory::Unknown,
        public string $tariffLabel = '',
        public ?BigDecimal $totalDue = null,
        public array $readings = [],
        public array $charges = [],
    ) {}

    public function periodDays(): ?int
    {
        if ($this->periodStart === null || $this->periodEnd === null || $this->periodEnd < $this->periodStart) {
            return null;
        }

        return Dates::daysBetween($this->periodStart, $this->periodEnd) + 1;
    }

    /** The month the bill is for: the end of its period, else its date. */
    public function month(): ?DateTimeImmutable
    {
        return $this->periodEnd ?? $this->billDate;
    }

    /** @return list<Reading> */
    public function readingsFor(Service $service, string $meterNumber = ''): array
    {
        $readings = array_values(array_filter($this->readings, fn (Reading $r) => $r->service === $service));

        if ($meterNumber !== '') {
            $sameMeter = array_values(array_filter($readings, fn (Reading $r) => $r->meterNumber === $meterNumber));

            if ($sameMeter !== []) {
                return $sameMeter;
            }
        }

        return $readings;
    }
}
