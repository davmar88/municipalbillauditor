<?php

namespace Tests\Unit\Audit;

use App\Audit\Rules\ConsecutiveEstimatesRule;
use Tests\Support\AuditScenario;
use Tests\TestCase;

class ConsecutiveEstimatesRuleTest extends TestCase
{
    private function water(string $readingType): array
    {
        return [['service' => 'water', 'reading_type' => $readingType, 'consumption' => 12, 'amount_cents' => 36000]];
    }

    public function test_three_estimates_in_a_row_is_high(): void
    {
        $s = new AuditScenario;
        $bills = $s->monthly([$this->water('actual'), $this->water('estimated'), $this->water('estimated'), $this->water('estimated')]);

        $findings = (new ConsecutiveEstimatesRule)->evaluate($s->context($bills[3]));

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('high', $f->severity->value);
        $this->assertSame(0.95, $f->confidence);
        $this->assertNull($f->estimatedOverchargeCents);
        $this->assertSame([
            'service' => 'water',
            'consecutive_count' => 3,
            'bill_ids' => [$bills[3]->id, $bills[2]->id, $bills[1]->id],
        ], $f->evidence);
        $this->assertStringContainsString('3 bills in a row', $f->explanation);
    }

    public function test_counts_the_whole_run(): void
    {
        $s = new AuditScenario;
        $bills = $s->monthly(array_fill(0, 4, $this->water('estimated')));

        $f = (new ConsecutiveEstimatesRule)->evaluate($s->context($bills[3]))[0];

        $this->assertSame(4, $f->evidence['consecutive_count']);
    }

    public function test_only_the_newest_bill_of_the_run_gets_the_finding(): void
    {
        $s = new AuditScenario;
        $bills = $s->monthly([$this->water('estimated'), $this->water('estimated'), $this->water('estimated'), $this->water('estimated'), $this->water('actual')]);
        $rule = new ConsecutiveEstimatesRule;

        $this->assertSame([], $rule->evaluate($s->context($bills[2])));
        $this->assertSame(4, $rule->evaluate($s->context($bills[3]))[0]->evidence['consecutive_count']);
        $this->assertSame([], $rule->evaluate($s->context($bills[4])));
    }

    public function test_two_estimates_in_a_row_is_not_enough(): void
    {
        $s = new AuditScenario;
        $bills = $s->monthly([$this->water('actual'), $this->water('estimated'), $this->water('estimated')]);

        $this->assertSame([], (new ConsecutiveEstimatesRule)->evaluate($s->context($bills[2])));
    }

    public function test_an_actual_reading_breaks_the_run(): void
    {
        $s = new AuditScenario;
        $bills = $s->monthly([$this->water('estimated'), $this->water('actual'), $this->water('estimated'), $this->water('estimated')]);

        $this->assertSame([], (new ConsecutiveEstimatesRule)->evaluate($s->context($bills[3])));
    }

    public function test_only_the_same_service_counts_and_once_per_service(): void
    {
        $s = new AuditScenario;
        $electricityEstimated = [['service' => 'electricity', 'reading_type' => 'estimated', 'consumption' => 300, 'amount_cents' => 90000]];
        $bills = $s->monthly([
            $electricityEstimated,
            $electricityEstimated,
            [
                ['service' => 'water', 'reading_type' => 'estimated', 'consumption' => 6, 'amount_cents' => 18000],
                ['service' => 'water', 'reading_type' => 'estimated', 'consumption' => 6, 'amount_cents' => 18000],
                ...$electricityEstimated,
            ],
        ]);

        $findings = (new ConsecutiveEstimatesRule)->evaluate($s->context($bills[2]));

        $this->assertCount(1, $findings);
        $this->assertSame('electricity', $findings[0]->evidence['service']);
    }
}
