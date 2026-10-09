<?php

namespace Tests\Unit\Audit;

use Tests\Support\AuditScenario;
use Tests\TestCase;

class AuditContextTest extends TestCase
{
    public function test_history_is_earlier_bills_most_recent_first(): void
    {
        $s = new AuditScenario;
        $bills = $s->monthly([[], [], [], []]);

        $history = $s->context($bills[2])->history;

        $this->assertSame([$bills[1]->id, $bills[0]->id], $history->pluck('id')->all());
    }

    public function test_next_is_the_bill_immediately_after(): void
    {
        $s = new AuditScenario;
        $bills = $s->monthly([[], [], [], []]);

        $this->assertSame($bills[2]->id, $s->context($bills[1])->next->id);
        $this->assertNull($s->context($bills[3])->next);
    }

    public function test_a_shared_boundary_day_still_counts_as_before(): void
    {
        $s = new AuditScenario;
        $previous = $s->bill('2026-07-20', '2026-08-20', []);
        $current = $s->bill('2026-08-20', '2026-09-19', []);

        $this->assertSame([$previous->id], $s->context($current)->history->pluck('id')->all());
    }

    public function test_falls_back_to_bill_date_when_periods_are_missing(): void
    {
        $s = new AuditScenario;
        $older = $s->bill(null, null, [], ['bill_date' => '2026-08-25']);
        $current = $s->bill(null, null, [], ['bill_date' => '2026-09-25']);
        $s->bill(null, null, [], ['bill_date' => '2026-10-25']);
        $s->bill(null, null, [], ['bill_date' => null]);

        $this->assertSame([$older->id], $s->context($current)->history->pluck('id')->all());
    }

    public function test_overlapping_periods_are_not_history(): void
    {
        $s = new AuditScenario;
        $s->bill('2026-08-01', '2026-08-31', []);
        $current = $s->bill('2026-08-15', '2026-09-15', []);

        $this->assertTrue($s->context($current)->history->isEmpty());
    }
}
