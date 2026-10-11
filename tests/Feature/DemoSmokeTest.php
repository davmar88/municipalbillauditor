<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Dispute;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every screen, as each demo account, with realistic data behind it.
 */
class DemoSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The demo is built relative to today. Fix the day so the figures
        // below hold all year: September has 30 days, and last month's
        // credit falls in the same year.
        CarbonImmutable::setTestNow('2026-10-11 09:00:00');
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function as(string $email): User
    {
        $user = User::firstWhere('email', $email);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_household_sees_its_flagged_bill(): void
    {
        $user = $this->as('household@example.com');
        $bill = $user->organisation->bills()->latest('bill_date')->first();

        $this->get(route('bills.index'))->assertInertia(fn (Assert $page) => $page->component('bills/index')->has('bills', 6));
        $this->get(route('bills.show', $bill))->assertInertia(fn (Assert $page) => $page
            ->component('bills/show')
            ->where('bill.likelyTotal', 'R924.00')
            ->where('bill.upToTotal', 'R1,013.60')
            ->where('bill.questions', 1)
            ->where('bill.findings.1.calculation', '16 kL ÷ 30 days × 4 days × R42.00 = R89.60')
            ->has('bill.history', 2),
        );
        $this->get(route('disputes.create', $bill))->assertInertia(fn (Assert $page) => $page->component('disputes/create'));
        $this->get(route('properties.index'))->assertInertia(fn (Assert $page) => $page->component('properties/index')->has('properties', 1));
        $this->get(route('account.edit'))->assertInertia(fn (Assert $page) => $page->component('settings/account'));
    }

    public function test_the_agent_sees_the_portfolio_sorted_by_urgency(): void
    {
        $this->as('agent@example.com');

        $this->get(route('portfolio'))->assertInertia(fn (Assert $page) => $page
            ->component('portfolio/index')
            ->where('kpis.units', 8)
            ->where('kpis.missing', 1)
            ->where('kpis.creditedThisYear', 'R714.00')
            ->where('kpis.openFlags', 3)
            ->where('flagsByType', fn ($types) => collect($types)->sum('count') === 3)
            ->where('rows.0.state', 'escalated')
            ->where('flagsByType.0.type', 'estimated_reading'),
        );

        $this->get(route('bills.index'))->assertOk();
        $this->get(route('disputes.index'))->assertInertia(fn (Assert $page) => $page->component('disputes/index')->has('disputes', 4));

        foreach (Dispute::where('organisation_id', User::firstWhere('email', 'agent@example.com')->organisation_id)->get() as $dispute) {
            $this->get(route('disputes.show', $dispute))->assertOk();
        }
    }

    public function test_the_business_sees_its_claim_and_fee(): void
    {
        $user = $this->as('business@example.com');
        $claim = Dispute::where('organisation_id', $user->organisation_id)->sole();

        $this->get(route('disputes.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->component('disputes/show')
            ->where('isBusiness', true)
            ->where('dispute.stage', 'awaiting_credit')
            ->where('dispute.fees.backRefund', 'R90,000.00')
            ->where('dispute.fees.monthlySaving', 'R7,500.00')
            ->where('dispute.fees.totalFee', 'R45,000.00')
            ->where('dispute.tariff.after.label', 'Bulk residential'),
        );
    }

    public function test_staff_see_the_work_queue_and_reference_data(): void
    {
        $this->as('staff@example.com');

        $this->get(route('staff.index'))->assertInertia(fn (Assert $page) => $page
            ->component('staff/index')
            ->has('toLodge', 1)
            ->has('needsEscalation', 1)
            ->where('needsEscalation.0.councillorLetter.subject', fn (string $subject) => str_starts_with($subject, 'Unresolved billing dispute')),
        );
        $this->get(route('staff.reference'))->assertInertia(fn (Assert $page) => $page
            ->component('staff/reference')
            ->has('metros', 3)
            ->has('tariffs', 4),
        );

        $bill = Bill::query()->whereNotNull('file_path')->first();
        $this->assertNull($bill, 'Demo bills have no files.');
    }
}
