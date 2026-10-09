<?php

namespace Tests\Unit\Audit;

use App\Audit\Rules\ArithmeticMismatchRule;
use Tests\Support\AuditScenario;
use Tests\TestCase;

class ArithmeticMismatchRuleTest extends TestCase
{
    private function evaluate(?int $total): array
    {
        $s = new AuditScenario;
        $bill = $s->bill('2026-01-01', '2026-01-31', [
            ['service' => 'water', 'amount_cents' => 36000],
            ['service' => 'rates', 'amount_cents' => 64000],
        ], ['total_cents' => $total]);

        return (new ArithmeticMismatchRule)->evaluate($s->context($bill));
    }

    public function test_no_total_means_no_finding(): void
    {
        $this->assertSame([], $this->evaluate(null));
    }

    public function test_differences_up_to_five_rand_are_ignored(): void
    {
        $this->assertSame([], $this->evaluate(100000));
        $this->assertSame([], $this->evaluate(100500));
        $this->assertSame([], $this->evaluate(99500));
    }

    public function test_larger_differences_are_low_information(): void
    {
        $findings = $this->evaluate(100501);

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('low', $f->severity->value);
        $this->assertSame(0.5, $f->confidence);
        $this->assertNull($f->estimatedOverchargeCents);
        $this->assertNull($f->service);
        $this->assertSame(['total_cents' => 100501, 'line_items_sum_cents' => 100000, 'difference_cents' => 501], $f->evidence);
        $this->assertStringContainsString('R1 005.01', $f->explanation);
        $this->assertStringContainsString('R1 000.00', $f->explanation);
    }

    public function test_total_below_the_sum_is_also_reported(): void
    {
        $f = $this->evaluate(90000)[0];

        $this->assertSame(-10000, $f->evidence['difference_cents']);
        $this->assertStringContainsString('R100.00', $f->explanation);
    }
}
