<?php

namespace Database\Factories;

use App\Audit\Enums\TariffCategory;
use App\Enums\BillSource;
use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    public function definition(): array
    {
        $month = CarbonImmutable::parse('2026-09-01');

        return [
            'organisation_id' => fn (array $attributes) => Property::find($attributes['property_id'])?->organisation_id,
            'property_id' => Property::factory(),
            'source' => BillSource::Manual,
            'status' => BillStatus::Received,
            'bill_date' => $month->addMonthNoOverflow()->addDays(2),
            'period_start' => $month,
            'period_end' => $month->endOfMonth()->startOfDay(),
            'account_number' => fake()->numerify('#### #### ####'),
            'tariff_label' => 'Residential',
            'tariff_category' => TariffCategory::Residential,
            'read_method' => 'manual',
        ];
    }

    /** A bill for the given calendar month, dated the 3rd of the next. */
    public function forMonth(string $yearMonth): static
    {
        $month = CarbonImmutable::parse($yearMonth.'-01');

        return $this->state(fn () => [
            'bill_date' => $month->addMonthNoOverflow()->addDays(2),
            'period_start' => $month,
            'period_end' => $month->endOfMonth()->startOfDay(),
        ]);
    }
}
