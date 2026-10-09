<?php

namespace Tests;

use App\Audit\AuditService;
use App\Extraction\BillExtractor;
use App\Models\Bill;
use App\Models\Property;
use App\Models\User;
use App\Services\BillService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBillExtractor;

abstract class TestCase extends BaseTestCase
{
    protected FakeBillExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never call the real Anthropic API.
        $this->extractor = new FakeBillExtractor;
        $this->app->instance(BillExtractor::class, $this->extractor);
    }

    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Creates a bill through the real service (line items + audit).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    protected function makeBill(Property $property, array $lines, array $attributes = []): Bill
    {
        return $this->app->make(BillService::class)->create(
            $property,
            $property->user,
            [...$attributes, 'line_items' => $lines],
            null,
        );
    }

    /**
     * Monthly bills for a property, oldest first, each with one water line.
     * The last bill uses $last for its water line.
     *
     * @param  list<float>  $history  water usage (kl) on earlier bills
     * @param  array<string, mixed>  $last
     * @return list<Bill>
     */
    protected function waterHistory(Property $property, array $history, array $last): array
    {
        $bills = [];
        $month = 1;
        foreach ([...array_map(fn ($kl) => ['consumption' => $kl], $history), $last] as $line) {
            $start = sprintf('2026-%02d-01', $month);
            $end = date('Y-m-t', strtotime($start));
            $bills[] = $this->makeBill($property, [[
                'service' => 'water',
                'description' => 'Water consumption',
                'reading_type' => 'actual',
                'unit' => 'kl',
                'amount_cents' => (int) round(($line['consumption'] ?? 10) * 3000),
                ...$line,
            ]], [
                'bill_date' => date('Y-m-d', strtotime($end.' +5 days')),
                'period_start' => $start,
                'period_end' => $end,
            ]);
            $month++;
        }

        return $bills;
    }

    protected function audit(): AuditService
    {
        return $this->app->make(AuditService::class);
    }
}
