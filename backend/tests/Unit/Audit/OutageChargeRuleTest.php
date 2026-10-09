<?php

namespace Tests\Unit\Audit;

use App\Audit\Rules\OutageChargeRule;
use Tests\Support\AuditScenario;
use Tests\TestCase;

/**
 * Billing periods are measured in Africa/Johannesburg (UTC+2).
 */
class OutageChargeRuleTest extends TestCase
{
    private function evaluate(AuditScenario $s, $bill): array
    {
        return (new OutageChargeRule)->evaluate($s->context($bill));
    }

    /** April 2026 (30 days) after three months of 12 kl at R30/kl. */
    private function april(AuditScenario $s, float $usage, array $history = [12, 12, 12])
    {
        return $s->usageSeries('water', $history, ['consumption' => $usage], 'actual');
    }

    public function test_outage_under_ten_percent_of_the_period_is_ignored(): void
    {
        $s = new AuditScenario;
        $bill = $this->april($s, 30);
        // 2 of 30 days = 6.7%
        $s->outage('water', '2026-04-10T00:00:00+02:00', '2026-04-12T00:00:00+02:00');

        $this->assertSame([], $this->evaluate($s, $bill));
    }

    public function test_supply_off_for_the_whole_period_with_usage_is_high_for_the_full_amount(): void
    {
        $s = new AuditScenario;
        $bill = $this->april($s, 12);
        $s->outage('water', '2026-03-31T20:00:00+02:00', '2026-05-01T08:00:00+02:00');

        $findings = $this->evaluate($s, $bill);

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('high', $f->severity->value);
        $this->assertSame(0.85, $f->confidence);
        $this->assertSame(36000, $f->estimatedOverchargeCents);
        $this->assertSame(30, $f->evidence['period_days']);
        $this->assertSame(1.0, $f->evidence['outage_fraction']);
        $this->assertSame(30.0, $f->evidence['outage_days']);
    }

    public function test_usage_well_above_expected_for_a_partial_outage_is_medium(): void
    {
        $s = new AuditScenario;
        $bill = $this->april($s, 12);
        // 15 of 30 days: expected 12 × 0.5 = 6 kl
        $s->outage('water', '2026-04-01T00:00:00+02:00', '2026-04-16T00:00:00+02:00');

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame('medium', $f->severity->value);
        $this->assertSame(0.65, $f->confidence);
        // (12 - 6) × (36000 / 12)
        $this->assertSame(18000, $f->estimatedOverchargeCents);
        $this->assertSame([
            'service' => 'water',
            'outage_days' => 15.0,
            'period_days' => 30,
            'outage_fraction' => 0.5,
            'consumption' => 12.0,
            'expected_consumption' => 6.0,
        ], $f->evidence);
        $this->assertStringContainsString('R180.00', $f->explanation);
    }

    public function test_usage_consistent_with_the_outage_is_not_flagged(): void
    {
        $s = new AuditScenario;
        // Expected 6 kl; 6.5 is within the 15% tolerance (6.9).
        $bill = $this->april($s, 6.5);
        $s->outage('water', '2026-04-01T00:00:00+02:00', '2026-04-16T00:00:00+02:00');

        $this->assertSame([], $this->evaluate($s, $bill));
    }

    public function test_overlapping_outages_are_merged(): void
    {
        $s = new AuditScenario;
        $bill = $this->april($s, 12);
        $s->outage('water', '2026-04-01T00:00:00+02:00', '2026-04-10T00:00:00+02:00');
        $s->outage('water', '2026-04-05T00:00:00+02:00', '2026-04-16T00:00:00+02:00');

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame(15.0, $f->evidence['outage_days']);
        $this->assertSame(0.5, $f->evidence['outage_fraction']);
    }

    public function test_without_enough_history_it_is_low_with_no_overcharge(): void
    {
        $s = new AuditScenario;
        // February 2026 (28 days) with only one earlier bill.
        $bill = $s->usageSeries('water', [12], ['consumption' => 12.0]);
        $s->outage('water', '2026-02-01T00:00:00+02:00', '2026-02-15T00:00:00+02:00');

        $f = $this->evaluate($s, $bill)[0];

        $this->assertSame('low', $f->severity->value);
        $this->assertSame(28, $f->evidence['period_days']);
        $this->assertSame(0.4, $f->confidence);
        $this->assertNull($f->estimatedOverchargeCents);
        $this->assertNull($f->evidence['expected_consumption']);
    }

    public function test_bills_without_a_period_are_skipped(): void
    {
        $s = new AuditScenario;
        $bill = $s->bill(null, null, [['service' => 'water', 'consumption' => 12, 'amount_cents' => 36000]], ['bill_date' => '2026-04-30']);
        $s->outage('water', '2026-04-01T00:00:00+02:00', '2026-04-30T00:00:00+02:00');

        $this->assertSame([], $this->evaluate($s, $bill));
    }

    public function test_needs_a_charge_for_the_same_service(): void
    {
        $s = new AuditScenario;
        $bill = $s->bill('2026-04-01', '2026-04-30', [
            ['service' => 'water', 'consumption' => 0, 'amount_cents' => 0],
            ['service' => 'electricity', 'consumption' => 300, 'amount_cents' => 90000],
        ]);
        $s->outage('water', '2026-04-01T00:00:00+02:00', '2026-04-30T00:00:00+02:00');

        $this->assertSame([], $this->evaluate($s, $bill));
    }

    public function test_outage_of_another_service_does_not_count(): void
    {
        $s = new AuditScenario;
        $bill = $this->april($s, 12);
        $s->outage('electricity', '2026-04-01T00:00:00+02:00', '2026-04-30T00:00:00+02:00');

        $this->assertSame([], $this->evaluate($s, $bill));
    }

    public function test_outage_outside_the_period_does_not_count(): void
    {
        $s = new AuditScenario;
        $bill = $this->april($s, 12);
        $s->outage('water', '2026-05-01T00:00:00+02:00', '2026-05-20T00:00:00+02:00');

        $this->assertSame([], $this->evaluate($s, $bill));
    }
}
