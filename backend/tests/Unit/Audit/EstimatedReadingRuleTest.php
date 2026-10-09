<?php

namespace Tests\Unit\Audit;

use App\Audit\Rules\EstimatedReadingRule;
use Tests\Support\AuditScenario;
use Tests\TestCase;

class EstimatedReadingRuleTest extends TestCase
{
    private function evaluate(AuditScenario $s, $bill): array
    {
        return (new EstimatedReadingRule)->evaluate($s->context($bill));
    }

    public function test_estimated_reading_without_history_is_low_with_no_overcharge(): void
    {
        $s = new AuditScenario;
        $bill = $s->usageSeries('water', [], ['consumption' => 20.0, 'reading_type' => 'estimated']);

        $findings = $this->evaluate($s, $bill);

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('estimated_reading', $f->rule->value);
        $this->assertSame('low', $f->severity->value);
        $this->assertSame(0.9, $f->confidence);
        $this->assertNull($f->estimatedOverchargeCents);
        $this->assertSame([
            'service' => 'water',
            'consumption' => 20.0,
            'median_actual_consumption' => null,
            'actual_bills_compared' => 0,
        ], $f->evidence);
    }

    public function test_estimate_more_than_20_percent_above_actual_median_is_medium_with_overcharge(): void
    {
        $s = new AuditScenario;
        // R30/kl; median of actual readings = 11 kl.
        $bill = $s->usageSeries('water', [10, 12, 11], ['consumption' => 20.0, 'reading_type' => 'estimated']);

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame('medium', $f->severity->value);
        // (20 - 11) × (60000 / 20) = 27000
        $this->assertSame(27000, $f->estimatedOverchargeCents);
        $this->assertSame(11.0, $f->evidence['median_actual_consumption']);
        $this->assertSame(3, $f->evidence['actual_bills_compared']);
        $this->assertStringContainsString('R600.00', $f->explanation);
        $this->assertStringContainsString('R270.00', $f->explanation);
        $this->assertStringContainsString('may', $f->explanation);
        $this->assertStringContainsString('Read your meter', $f->explanation);
    }

    public function test_estimate_close_to_usual_stays_low(): void
    {
        $s = new AuditScenario;
        $bill = $s->usageSeries('water', [10, 12, 11], ['consumption' => 13.0, 'reading_type' => 'estimated']);

        $f = $this->evaluate($s, $bill)[0];

        // 13 is not more than 11 × 1.2 = 13.2
        $this->assertSame('low', $f->severity->value);
        $this->assertSame(6000, $f->estimatedOverchargeCents);
    }

    public function test_estimate_below_usual_has_zero_overcharge(): void
    {
        $s = new AuditScenario;
        $bill = $s->usageSeries('water', [10, 12, 11], ['consumption' => 8.0, 'reading_type' => 'estimated']);

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame('low', $f->severity->value);
        $this->assertSame(0, $f->estimatedOverchargeCents);
    }

    public function test_overcharge_needs_at_least_two_previous_actual_readings(): void
    {
        $s = new AuditScenario;
        $bill = $s->usageSeries('water', [10], ['consumption' => 20.0, 'reading_type' => 'estimated']);

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame('medium', $f->severity->value);
        $this->assertNull($f->estimatedOverchargeCents);
        $this->assertSame(1, $f->evidence['actual_bills_compared']);
    }

    public function test_previous_estimated_readings_are_not_used_as_the_baseline(): void
    {
        $s = new AuditScenario;
        $s->monthly([
            [['service' => 'water', 'reading_type' => 'actual', 'consumption' => 10, 'amount_cents' => 30000]],
            [['service' => 'water', 'reading_type' => 'estimated', 'consumption' => 40, 'amount_cents' => 120000]],
            [['service' => 'water', 'reading_type' => 'actual', 'consumption' => 12, 'amount_cents' => 36000]],
        ]);
        $bill = $s->bill('2026-04-01', '2026-04-30', [
            ['service' => 'water', 'reading_type' => 'estimated', 'consumption' => 20, 'amount_cents' => 60000],
        ]);

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame(11.0, $f->evidence['median_actual_consumption']);
        $this->assertSame(2, $f->evidence['actual_bills_compared']);
    }

    public function test_compares_at_most_six_previous_actual_readings(): void
    {
        $s = new AuditScenario;
        $bill = $s->usageSeries('electricity', [900, 900, 300, 300, 300, 300, 300, 300], ['consumption' => 500.0, 'reading_type' => 'estimated']);

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame(6, $f->evidence['actual_bills_compared']);
        $this->assertSame(300.0, $f->evidence['median_actual_consumption']);
        $this->assertSame('electricity', $f->evidence['service']);
    }

    public function test_actual_readings_and_unmetered_services_produce_nothing(): void
    {
        $s = new AuditScenario;
        $bill = $s->bill('2026-01-01', '2026-01-31', [
            ['service' => 'water', 'reading_type' => 'actual', 'consumption' => 12, 'amount_cents' => 36000],
            ['service' => 'sewerage', 'reading_type' => 'estimated', 'consumption' => 10, 'amount_cents' => 20000],
        ]);

        $this->assertSame([], $this->evaluate($s, $bill));
    }
}
