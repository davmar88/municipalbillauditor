<?php

namespace Database\Factories;

use App\Enums\DisputeMode;
use App\Models\Bill;
use App\Models\Dispute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bill_id' => Bill::factory(),
            'organisation_id' => fn (array $attributes) => Bill::find($attributes['bill_id'])->organisation_id,
            'property_id' => fn (array $attributes) => Bill::find($attributes['bill_id'])->property_id,
            'mode' => DisputeMode::Self,
            'letter_subject' => 'Dispute',
            'letter_body' => 'I dispute these charges.',
            'disputed_total' => '924.00',
        ];
    }
}
