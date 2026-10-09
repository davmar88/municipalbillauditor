<?php

namespace Tests\Unit;

use App\Support\Dates;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function amounts(): array
    {
        return [
            'zero' => [0, 'R0.00'],
            'cents only' => [5, 'R0.05'],
            'under a thousand' => [99999, 'R999.99'],
            'a thousand' => [100000, 'R1 000.00'],
            'example' => [152340, 'R1 523.40'],
            'millions' => [1234567890, 'R12 345 678.90'],
            'negative' => [-1200, '-R12.00'],
            'negative cents' => [-50, '-R0.50'],
            'negative thousands' => [-152340, '-R1 523.40'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_formats_rand(int $cents, string $expected): void
    {
        $this->assertSame($expected, Money::format($cents));
    }

    public function test_formats_dates(): void
    {
        $this->assertSame('25 Sep 2026', Dates::short(CarbonImmutable::parse('2026-09-25')));
        $this->assertSame('5 Oct 2026', Dates::short(CarbonImmutable::parse('2026-10-05')));
        $this->assertSame('25 September 2026', Dates::long(CarbonImmutable::parse('2026-09-25')));
        $this->assertNull(Dates::short(null));
    }

    public function test_formats_quantities(): void
    {
        $this->assertSame('38', Quantity::number(38.0));
        $this->assertSame('12.2', Quantity::number(12.24));
        $this->assertSame('1 350', Quantity::number(1350.0));
        $this->assertSame('-3', Quantity::number(-3.0));
    }
}
