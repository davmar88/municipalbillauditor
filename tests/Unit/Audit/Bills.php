<?php

namespace Tests\Unit\Audit;

use App\Audit\Data\BillSnapshot;
use App\Audit\Data\ChargeLine;
use App\Audit\Data\Reading;
use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Builders for engine snapshots. The scenarios are the ones on the design
 * canvas, so the tests also check that the design's figures add up.
 */
final class Bills
{
    /**
     * A bill for one calendar month, dated the 3rd of the next month.
     *
     * @param  list<ChargeLine>  $extraCharges
     */
    public static function month(
        int $year,
        int $month,
        string|int|null $water = null,
        ReadingType $waterType = ReadingType::Actual,
        string|int|null $waterRate = null,
        string|int|null $electricity = null,
        ReadingType $electricityType = ReadingType::Actual,
        string|int|null $electricityRate = null,
        TariffCategory $tariff = TariffCategory::Residential,
        string $tariffLabel = 'Residential',
        array $extraCharges = [],
        ?string $total = null,
    ): BillSnapshot {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $readings = [];
        $charges = [];

        if ($water !== null) {
            $readings[] = new Reading(Service::Water, BigDecimal::of($water), $waterType, 'kL', '07-118843');

            if ($waterRate !== null) {
                $charges[] = new ChargeLine(Service::Water, "Water {$water} kL", BigDecimal::of($water)->multipliedBy($waterRate));
            }
        }

        if ($electricity !== null) {
            $readings[] = new Reading(Service::Electricity, BigDecimal::of($electricity), $electricityType, 'kWh', '55-0091-22');

            if ($electricityRate !== null) {
                $charges[] = new ChargeLine(Service::Electricity, "Electricity {$electricity} kWh", BigDecimal::of($electricity)->multipliedBy($electricityRate));
            }
        }

        return new BillSnapshot(
            billDate: $start->modify('first day of next month')->modify('+2 days'),
            periodStart: $start,
            periodEnd: $start->modify('last day of this month'),
            tariff: $tariff,
            tariffLabel: $tariffLabel,
            totalDue: $total !== null ? BigDecimal::of($total) : null,
            readings: $readings,
            charges: [...$charges, ...$extraCharges],
        );
    }

    /**
     * Five actual water readings averaging 16 kL, newest first.
     *
     * @return list<BillSnapshot>
     */
    public static function householdHistory(): array
    {
        return array_map(
            fn (array $row) => self::month($row[0], $row[1], water: $row[2], waterRate: 42),
            [[2026, 8, 16], [2026, 7, 16], [2026, 6, 17], [2026, 5, 15], [2026, 4, 16]],
        );
    }

    /** The household bill from the design: 38 kL estimated, R2,996.45 due. */
    public static function householdSeptember(): BillSnapshot
    {
        return self::month(
            2026,
            9,
            water: 38,
            waterType: ReadingType::Estimated,
            waterRate: 42,
            extraCharges: [
                new ChargeLine(Service::Sanitation, 'Sanitation', BigDecimal::of('562.05')),
                new ChargeLine(Service::Refuse, 'Refuse removal', BigDecimal::of('318.40')),
                new ChargeLine(Service::Rates, 'Property rates', BigDecimal::of('520.00')),
            ],
            total: '2996.45',
        );
    }

    /**
     * The agent's unit from the design: water estimated since July against an
     * actual average of 12 kL, electricity averaging 470 kWh. Newest first.
     *
     * @return list<BillSnapshot>
     */
    public static function agentUnitHistory(): array
    {
        $rows = [
            [2026, 8, 29, ReadingType::Estimated, 480], [2026, 7, 30, ReadingType::Estimated, 470],
            [2026, 6, 12, ReadingType::Actual, 450], [2026, 5, 12, ReadingType::Actual, 490],
            [2026, 4, 13, ReadingType::Actual, 470], [2026, 3, 12, ReadingType::Actual, 460],
            [2026, 2, 11, ReadingType::Actual, 430], [2026, 1, 12, ReadingType::Actual, 520],
            [2025, 12, 13, ReadingType::Actual, 470], [2025, 11, 12, ReadingType::Actual, 480],
            [2025, 10, 11, ReadingType::Actual, 450],
        ];

        return array_map(fn (array $row) => self::month(
            $row[0],
            $row[1],
            water: $row[2],
            waterType: $row[3],
            waterRate: 42,
            electricity: $row[4],
            electricityRate: 3,
        ), $rows);
    }

    public static function agentUnitSeptember(): BillSnapshot
    {
        return self::month(2026, 9, water: 31, waterType: ReadingType::Estimated, waterRate: 42, electricity: 1140, electricityRate: 3);
    }

    /** A body corporate billed on a business tariff for a year. */
    public static function businessTariffBill(int $year, int $month): BillSnapshot
    {
        return self::month(
            $year,
            $month,
            water: 500,
            waterRate: 52,
            electricity: 5000,
            electricityRate: '3.50',
            tariff: TariffCategory::Business,
            tariffLabel: 'Business',
        );
    }

    /** @return array<string, BigDecimal> */
    public static function tariffRates(): array
    {
        return [
            'business:water' => BigDecimal::of('52.00'),
            'bulk_residential:water' => BigDecimal::of('42.00'),
            'business:electricity' => BigDecimal::of('3.50'),
            'bulk_residential:electricity' => BigDecimal::of('3.00'),
        ];
    }
}
