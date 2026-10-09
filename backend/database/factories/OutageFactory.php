<?php

namespace Database\Factories;

use App\Models\Outage;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outage>
 */
class OutageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'service' => 'water',
            'starts_at' => '2026-09-01 06:00:00',
            'ends_at' => '2026-09-09 18:00:00',
            'notes' => null,
        ];
    }
}
