<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'bill_date' => '2026-09-25',
            'period_start' => '2026-08-20',
            'period_end' => '2026-09-19',
            'due_date' => '2026-10-15',
            'total_cents' => null,
            'status' => 'audited',
            'extraction_source' => 'manual',
        ];
    }
}
