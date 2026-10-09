<?php

namespace Database\Factories;

use App\Models\Bill;
use App\Models\LineItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LineItem>
 */
class LineItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bill_id' => Bill::factory(),
            'service' => 'water',
            'description' => 'Water consumption',
            'tariff_category' => null,
            'reading_type' => 'actual',
            'previous_reading' => null,
            'current_reading' => null,
            'consumption' => 12.0,
            'unit' => 'kl',
            'amount_cents' => 36000,
        ];
    }
}
