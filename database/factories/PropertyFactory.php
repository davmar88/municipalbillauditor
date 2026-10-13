<?php

namespace Database\Factories;

use App\Audit\Enums\PropertyType;
use App\Models\Municipality;
use App\Models\Organisation;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'municipality_id' => fn () => Municipality::query()->where('slug', 'johannesburg')->value('id'),
            'name' => 'Home',
            'address' => fake()->streetAddress(),
            'suburb' => 'Parkhurst',
            'property_type' => PropertyType::Residential,
            'account_number' => fake()->numerify('#### #### ####'),
        ];
    }
}
