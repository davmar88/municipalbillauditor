<?php

namespace Tests\Feature;

use App\Audit\Enums\Service;
use App\Enums\CreditKind;
use App\Enums\DisputeMode;
use App\Enums\FindingState;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Outage;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Billing\Wallet;
use App\Services\BillIntake;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * From a checked bill to a lodged dispute, as each kind of customer.
 */
class DisputeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * The sample bill: water estimated at 38 kL against a usual 16 kL.
     */
    private function flaggedBill(User $user): Bill
    {
        return app(BillIntake::class)->createSample($user->organisation, $user);
    }

    public function test_a_household_approves_a_dispute_and_pays_from_credit(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
        $staff = User::factory()->staff()->create();
        $bill = $this->flaggedBill($user);

        $this->actingAs($user)->get(route('disputes.create', $bill))->assertInertia(fn (Assert $page) => $page
            ->component('disputes/create')
            ->where('dispute.disputedTotal', 'R924.00')
            ->where('quote.price', 'R49.00')
            ->where('quote.affordable', true)
            ->has('dispute.findings', 1),
        );

        $response = $this->post(route('disputes.store', $bill), ['letter_body' => 'My edited letter.', 'mandate' => '1']);

        $dispute = Dispute::sole();
        $response->assertRedirect(route('disputes.show', $dispute));
        $this->assertSame(DisputeMode::Managed, $dispute->mode);
        $this->assertSame('My edited letter.', $dispute->letter_body);
        $this->assertSame('51.00', app(Wallet::class)->balance($user->organisation));

        // The bill is locked once its dispute is sent.
        $this->post(route('bills.recheck', $bill))->assertForbidden();
        $this->post(route('findings.dismiss', $bill->findings->first()))->assertForbidden();

        // Customers can't reach the staff queue.
        $this->get(route('staff.index'))->assertForbidden();

        $this->actingAs($staff)->post(route('staff.disputes.lodge', $dispute), [
            'lodged_on' => now()->toDateString(),
            'reference' => 'JHB-1001',
        ])->assertRedirect();

        $dispute->refresh();
        $this->assertTrue($dispute->lodged_on->isToday());
        $this->assertSame('JHB-1001', $dispute->reference);
    }

    public function test_a_dispute_needs_enough_credit(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $bill = $this->flaggedBill($user);

        $this->actingAs($user)->get(route('disputes.create', $bill))->assertInertia(fn (Assert $page) => $page
            ->where('quote.affordable', false)
            ->where('quote.shortfall', 'R49.00'),
        );

        $this->post(route('disputes.store', $bill), ['letter_body' => 'Letter', 'mandate' => '1'])->assertSessionHasErrors('credit');
        $this->assertNull(Dispute::sole()->submitted_on);
        $this->assertSame(0, $user->organisation->creditTransactions()->count());
    }

    public function test_customers_record_what_happened(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
        $bill = $this->flaggedBill($user);
        $this->actingAs($user)->post(route('disputes.store', $bill), ['letter_body' => 'Letter', 'mandate' => '1']);
        $dispute = Dispute::sole();
        app(DisputeService::class)->lodge($dispute, CarbonImmutable::today(), null, null);

        $this->post(route('disputes.events.store', $dispute), ['kind' => 'acknowledged', 'happened_on' => now()->toDateString()])
            ->assertSessionHasErrors('reference');

        $this->post(route('disputes.events.store', $dispute), ['kind' => 'acknowledged', 'happened_on' => now()->toDateString(), 'reference' => '8004 1123 77'])
            ->assertSessionHasNoErrors();
        $this->assertSame('8004 1123 77', $dispute->refresh()->reference);

        $this->post(route('disputes.events.store', $dispute), ['kind' => 'credit_received', 'happened_on' => now()->toDateString(), 'amount' => '924.00']);
        $this->assertSame('resolved', $dispute->refresh()->stage()->value);
        $this->assertSame('924.00', $dispute->credit_amount);

    }

    public function test_a_dispute_with_no_reply_gets_a_councillor_letter(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->withCredit()->create();
        $bill = $this->flaggedBill($user);
        $this->actingAs($user)->post(route('disputes.store', $bill), ['letter_body' => 'Letter', 'mandate' => '1']);
        $dispute = Dispute::sole();
        app(DisputeService::class)->lodge($dispute, CarbonImmutable::today(), null, null);

        CarbonImmutable::setTestNow(now()->addDays(25));

        $this->get(route('disputes.show', $dispute))->assertInertia(fn (Assert $page) => $page
            ->where('dispute.stage', 'reply_overdue')
            ->where('councillorLetter.subject', fn (string $subject) => str_starts_with($subject, 'Unresolved billing dispute')),
        );
    }

    public function test_businesses_pay_as_they_go_like_everyone_else(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Business)->create();
        $bill = $this->flaggedBill($user);

        // No plan to fall back on: without credit, the dispute waits.
        $this->actingAs($user)->get(route('disputes.create', $bill))->assertInertia(fn (Assert $page) => $page
            ->where('quote.price', 'R49.00')
            ->where('quote.affordable', false)
            ->missing('quote.coveredByPlan'),
        );
        $this->post(route('disputes.store', $bill), ['letter_body' => 'Letter', 'mandate' => '1'])->assertSessionHasErrors('credit');

        app(Wallet::class)->credit($user->organisation, CreditKind::TopUp, '49', 'test-top-up', 'Card top-up');
        $this->post(route('disputes.store', $bill), ['letter_body' => 'Letter', 'mandate' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull(Dispute::sole()->submitted_on);
        $this->assertSame('0.00', app(Wallet::class)->balance($user->organisation));
    }

    public function test_questions_are_answered_before_they_go_in_a_dispute(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $bill = $this->flaggedBill($user);
        $bill->property->update(['suburb' => 'Melville']);
        $month = $bill->period_start;

        Outage::create([
            'municipality_id' => $bill->property->municipality_id,
            'suburb' => 'melville',
            'service' => Service::Water,
            'starts_on' => $month->addDays(11),
            'ends_on' => $month->addDays(14),
        ]);

        app(AuditService::class)->run($bill->refresh());
        $outage = $bill->refresh()->findings->firstWhere('check_type.value', 'outage_charge');
        $this->assertTrue($outage->awaitingAnswer());

        $this->actingAs($user)->get(route('disputes.create', $bill))->assertInertia(fn (Assert $page) => $page
            ->where('pendingQuestions', 1)
            ->has('dispute.findings', 1),
        );

        $this->post(route('findings.answer', $outage), ['answer' => 'maybe'])->assertSessionHasErrors('answer');
        $this->post(route('findings.answer', $outage), ['answer' => 'yes'])->assertSessionHasNoErrors();
        $this->assertSame(FindingState::Confirmed, $outage->refresh()->state);

        // Answers survive a re-check.
        $this->post(route('bills.recheck', $bill))->assertRedirect();
        $this->assertSame('yes', $bill->refresh()->findings->firstWhere('check_type.value', 'outage_charge')->answer);

        $this->get(route('disputes.create', $bill))->assertInertia(fn (Assert $page) => $page
            ->where('pendingQuestions', 0)
            ->has('dispute.findings', 2),
        );
    }

    public function test_a_finding_can_be_dismissed_and_restored(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $bill = $this->flaggedBill($user);
        $finding = $bill->findings->first();

        $this->actingAs($user)->post(route('findings.dismiss', $finding))->assertRedirect();
        $this->assertSame(FindingState::Dismissed, $finding->refresh()->state);

        // Nothing left to dispute.
        $this->get(route('disputes.create', $bill))->assertRedirect(route('bills.show', $bill));

        $this->post(route('findings.restore', $finding));
        $this->assertSame(FindingState::Open, $finding->refresh()->state);
    }
}
