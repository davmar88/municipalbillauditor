<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nickname' => fake()->randomElement(['Home', 'Sunset Court', 'Flat 4', 'Granny flat', 'Shop']),
            'metro' => 'johannesburg',
            'account_number' => (string) fake()->numberBetween(5000000000, 5999999999),
            'address' => fake()->buildingNumber().' '.fake()->streetName().', Melville',
            'property_type' => 'residential',
        ];
    }
}
