<?php

namespace Database\Factories;

use App\Enums\OrganisationKind;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organisation>
 */
class OrganisationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => OrganisationKind::Household,
            'name' => fake()->name(),
        ];
    }

    public function agent(): static
    {
        return $this->state(fn () => ['kind' => OrganisationKind::Agent, 'name' => fake()->company()]);
    }

    public function business(): static
    {
        return $this->state(fn () => ['kind' => OrganisationKind::Business, 'name' => fake()->company()]);
    }
}
