<?php

namespace Tests\Feature;

use App\Enums\CreditKind;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\ClaudeUsageRecord;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Claude\ClaudeCosts;
use App\Services\Claude\ClaudeUsage;
use App\Services\Claude\TokenCount;
use App\Services\Reading\BillReader;
use App\Services\Reading\BillReadingFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeBillReader;
use Tests\TestCase;

/**
 * What every Claude call costs us, and the margin staff see.
 */
class ClaudeCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        config(['auditor.claude_prices.usd_zar' => '18.50']);
    }

    public function test_tokens_are_priced_per_million_by_model(): void
    {
        $costs = new ClaudeCosts;

        // 1,200 in at $4 and 300 out at $20.
        $this->assertSame('0.010800', (string) $costs->usd(new TokenCount('claude-opus-5-5', 1200, 300)));

        // Cache reads at their own price, cache writes at 1.25 times input.
        $this->assertSame('0.100000', (string) $costs->usd(new TokenCount('claude-sonnet-5-5', 10_000, 1_000, cacheRead: 100_000, cacheWrite: 20_000)));

        // A dated id uses its model's price. Haiku has no cache read price,
        // so cache reads cost the input price.
        $this->assertSame('0.000150', (string) $costs->usd(new TokenCount('claude-haiku-5-5-20260301', 0, 0, cacheRead: 1500)));

        $this->assertNull($costs->usd(new TokenCount('some-other-model', 1000, 1000)));
    }

    public function test_usage_is_read_from_each_model_when_a_fallback_served_the_reply(): void
    {
        $message = (object) [
            'model' => 'claude-sonnet-5-5',
            'usage' => (object) [
                'inputTokens' => 900, 'outputTokens' => 400, 'cacheReadInputTokens' => null, 'cacheCreationInputTokens' => null,
                'iterations' => [
                    (object) ['type' => 'message', 'model' => null, 'inputTokens' => 900, 'outputTokens' => 5, 'cacheReadInputTokens' => 0, 'cacheCreationInputTokens' => 0],
                    (object) ['type' => 'fallback_message', 'model' => 'claude-sonnet-5-5', 'inputTokens' => 900, 'outputTokens' => 400, 'cacheReadInputTokens' => 0, 'cacheCreationInputTokens' => 0],
                ],
            ],
        ];

        $usage = ClaudeUsage::fromMessage($message, 'claude-opus-5-5');

        $this->assertSame(['claude-opus-5-5', 'claude-sonnet-5-5'], array_map(fn (TokenCount $c) => $c->model, $usage->counts));
        $this->assertSame([5, 400], array_map(fn (TokenCount $c) => $c->output, $usage->counts));
    }

    public function test_every_bill_read_is_recorded_against_the_account_and_bill_even_when_it_fails(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
        $usage = new ClaudeUsage([new TokenCount('claude-opus-5-5', 1200, 300)]);

        $this->app->instance(BillReader::class, new FakeBillReader(FakeBillReader::output(), $usage));
        $this->actingAs($user)->post(route('bills.store'), ['bill' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')]);

        $record = ClaudeUsageRecord::sole();
        $bill = Bill::sole();
        $this->assertSame($user->organisation_id, $record->organisation_id);
        $this->assertTrue($record->subject->is($bill));
        $this->assertSame('bill_read', $record->purpose);
        $this->assertSame([1200, 300], [$record->input_tokens, $record->output_tokens]);
        $this->assertSame('0.010800', $record->cost_usd);
        $this->assertSame('0.1998', $record->cost_zar);

        $this->app->instance(BillReader::class, new FakeBillReader((new BillReadingFailed('Unreadable'))->withUsage($usage)));
        $this->post(route('bills.store'), ['bill' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')]);

        $this->assertSame(2, ClaudeUsageRecord::count());
    }

    public function test_staff_see_the_months_margin(): void
    {
        $household = User::factory()->forKind(OrganisationKind::Household)->create();
        $agent = User::factory()->forKind(OrganisationKind::Agent)->create();
        app(Wallet::class)->credit($household->organisation, CreditKind::TopUp, '100', 'payment:a', 'Card top-up');
        app(Wallet::class)->credit($agent->organisation, CreditKind::TopUp, '400', 'payment:b', 'Card top-up');
        app(ClaudeCosts::class)->record(new ClaudeUsage([new TokenCount('claude-opus-5-5', 1_000_000, 100_000)]), $household->organisation, null, 'bill_read');
        app(ClaudeCosts::class)->record(new ClaudeUsage([new TokenCount('mystery-model', 10, 10)]), $agent->organisation, null, 'reply_read');

        $this->actingAs($household)->get(route('staff.margin'))->assertForbidden();

        $this->actingAs(User::factory()->staff()->create())->get(route('staff.margin'))->assertInertia(fn (Assert $page) => $page
            ->component('staff/margin')
            // Everyone pays as they go: top-ups are all the revenue.
            ->where('report.topUps', 'R500.00')
            ->where('report.revenue', 'R500.00')
            ->missing('report.monthlyFees')
            // ($4 + 100,000 × $20 / 1m) × 18.50 = R111.00
            ->where('report.claudeCost', 'R111.00')
            ->where('report.margin', 'R389.00')
            ->where('report.marginPercent', '77.8%')
            ->where('report.unpricedCalls', 1)
            ->where('report.byOrganisation.0.name', $household->organisation->name),
        );
    }
}
