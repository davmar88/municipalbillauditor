<?php

namespace Tests\Support;

use App\Services\Reading\BillReader;
use App\Services\Reading\ExtractedBill;
use Throwable;

/**
 * Stands in for Claude: returns a fixed reading, or throws.
 */
class FakeBillReader implements BillReader
{
    /** @var list<string> */
    public array $mimeTypes = [];

    /**
     * @param  array<string, mixed>|Throwable  $result
     */
    public function __construct(private readonly array|Throwable $result) {}

    public function read(string $contents, string $mimeType): ExtractedBill
    {
        $this->mimeTypes[] = $mimeType;

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return ExtractedBill::fromArray($this->result);
    }

    /**
     * What the structured output looks like for a plain Johannesburg bill.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function output(array $overrides = []): array
    {
        return [
            'is_municipal_bill' => true,
            'municipality' => 'City of Johannesburg',
            'account_number' => '5021 4478 2210',
            'bill_date' => '2026-10-03',
            'due_date' => '2026-10-26',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'property_address' => '12 Fourth Avenue, Melville',
            'suburb' => 'Melville',
            'tariff' => 'Residential',
            'total_due' => 2672.45,
            'meter_readings' => [
                ['service' => 'water', 'meter_number' => '07-118843', 'previous' => 1204, 'current' => 1220, 'consumption' => 16, 'unit' => 'kL', 'reading_type' => 'actual'],
            ],
            'charges' => [
                ['service' => 'water', 'description' => 'Water 16 kL', 'amount' => 672.0, 'quantity' => 16, 'unit' => 'kL'],
                ['service' => 'sanitation', 'description' => 'Sanitation', 'amount' => 562.05, 'quantity' => null, 'unit' => null],
                ['service' => 'refuse', 'description' => 'Refuse removal', 'amount' => 318.40, 'quantity' => null, 'unit' => null],
                ['service' => 'rates', 'description' => 'Property rates', 'amount' => 1120.0, 'quantity' => null, 'unit' => null],
            ],
            'unreadable_notes' => null,
            ...$overrides,
        ];
    }
}
