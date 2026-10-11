<?php

namespace Tests\Unit\Audit;

use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;
use App\Audit\Money;
use App\Audit\Normalize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_rand_amounts(): void
    {
        $this->assertSame('R1,234.50', Money::format('1234.5'));
        $this->assertSame('R90,000.00', Money::format(90000));
        $this->assertSame('R0.00', Money::format(0));
        $this->assertSame('-R50.00', Money::format(-50));
        $this->assertSame('+R7,500.00', Money::format(7500, signed: true));
    }

    public function test_rounds_half_up_to_the_cent(): void
    {
        $this->assertSame('89.60', (string) Money::money('89.595'));
        $this->assertSame('0.13', (string) Money::money('0.125'));
    }

    public function test_formats_quantities_without_trailing_zeros(): void
    {
        $this->assertSame('1,140', Money::quantity('1140'));
        $this->assertSame('15.8', Money::quantity('15.80'));
        $this->assertSame('16', Money::quantity('16.0'));
    }

    public function test_reads_amounts_as_printed_on_a_bill(): void
    {
        $this->assertSame('1234.56', (string) Money::of('R1,234.56'));
        $this->assertSame('1234.56', (string) Money::of('R 1 234.56'));
    }

    #[DataProvider('tariffLabels')]
    public function test_normalises_tariff_labels(string $label, TariffCategory $expected): void
    {
        $this->assertSame($expected, Normalize::tariff($label));
    }

    public static function tariffLabels(): array
    {
        return [
            ['Residential', TariffCategory::Residential],
            ['Domestic', TariffCategory::Residential],
            ['Bulk Residential', TariffCategory::BulkResidential],
            ['Business', TariffCategory::Business],
            ['Commercial', TariffCategory::Business],
            // Contains "resid", but is a business tariff.
            ['Non-residential', TariffCategory::Business],
            ['', TariffCategory::Unknown],
            ['Tariff 7', TariffCategory::Unknown],
        ];
    }

    public function test_normalises_services_and_reading_types(): void
    {
        $this->assertSame(Service::Sanitation, Normalize::service('Sewerage (water-borne)'));
        $this->assertSame(Service::Sanitation, Normalize::service('Waste water'));
        $this->assertSame(Service::Water, Normalize::service('Water consumption'));
        $this->assertSame(Service::Electricity, Normalize::service('Electricity 412 kWh'));
        $this->assertSame(Service::Refuse, Normalize::service('Refuse removal'));
        $this->assertSame(Service::Rates, Normalize::service('Property rates'));
        $this->assertSame(ReadingType::Estimated, Normalize::readingType('E'));
        $this->assertSame(ReadingType::Estimated, Normalize::readingType('Interim'));
        $this->assertSame(ReadingType::Actual, Normalize::readingType('A'));
        $this->assertSame(ReadingType::Unknown, Normalize::readingType('X'));
    }
}
