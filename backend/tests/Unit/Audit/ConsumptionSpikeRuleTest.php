<?php

namespace Tests\Unit\Audit;

use App\Audit\Rules\ConsumptionSpikeRule;
use Tests\Support\AuditScenario;
use Tests\TestCase;

class ConsumptionSpikeRuleTest extends TestCase
{
    private function spike(string $service, array $history, float $latest, string $readingType = 'actual'): array
    {
        $s = new AuditScenario;
        $bill = $s->usageSeries($service, $history, ['consumption' => $latest, 'reading_type' => $readingType]);

        return (new ConsumptionSpikeRule)->evaluate($s->context($bill));
    }

    public function test_needs_at_least_three_previous_bills(): void
    {
        $this->assertSame([], $this->spike('water', [10, 10], 40));
    }

    public function test_more_than_twice_the_median_is_medium(): void
    {
        $findings = $this->spike('water', [10, 10, 10], 25);

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('consumption_spike', $f->rule->value);
        $this->assertSame('medium', $f->severity->value);
        $this->assertSame(0.7, $f->confidence);
        // (25 - 10) × (75000 / 25)
        $this->assertSame(45000, $f->estimatedOverchargeCents);
        $this->assertSame([
            'service' => 'water',
            'consumption' => 25.0,
            'median_consumption' => 10.0,
            'bills_compared' => 3,
            'ratio' => 2.5,
        ], $f->evidence);
        $this->assertSame('Water use is 2.5 times your usual', $f->title);
        // A spike can be a real leak: the explanation must say so.
        $this->assertStringContainsString('leak', $f->explanation);
        $this->assertStringContainsString('R450.00', $f->explanation);
    }

    public function test_more_than_three_times_the_median_is_high(): void
    {
        $f = $this->spike('water', [10, 10, 10], 35)[0];

        $this->assertSame('high', $f->severity->value);
        $this->assertSame(3.5, $f->evidence['ratio']);
    }

    public function test_exactly_twice_the_median_is_not_a_spike(): void
    {
        $this->assertSame([], $this->spike('water', [10, 10, 10], 20));
    }

    public function test_small_absolute_increases_are_ignored(): void
    {
        // 3.5 × the median but only 5 kl more (floor is 8 kl).
        $this->assertSame([], $this->spike('water', [2, 2, 2], 7));
        // 3.2 × but only 220 kWh more (floor is 250 kWh).
        $this->assertSame([], $this->spike('electricity', [100, 100, 100], 320));
    }

    public function test_electricity_above_the_floor_is_flagged(): void
    {
        $f = $this->spike('electricity', [100, 100, 100], 400)[0];

        $this->assertSame('high', $f->severity->value);
        $this->assertSame('electricity', $f->evidence['service']);
        $this->assertSame('Electricity use is 4 times your usual', $f->title);
    }

    public function test_estimated_readings_are_skipped(): void
    {
        $this->assertSame([], $this->spike('water', [10, 10, 10], 40, 'estimated'));
    }

    public function test_compares_with_the_median_of_up_to_six_previous_bills(): void
    {
        // The oldest bill (100 kl) is outside the six most recent.
        $f = $this->spike('water', [100, 10, 10, 10, 10, 10, 10], 40)[0];

        $this->assertSame(6, $f->evidence['bills_compared']);
        $this->assertSame(10.0, $f->evidence['median_consumption']);
    }
}
