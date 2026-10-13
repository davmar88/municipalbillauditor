<?php

namespace Tests\Feature;

use App\Enums\OrganisationKind;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Top-ups by card and EFT: checkout, Paystack's webhook and asking
 * Paystack about payments still open. There are no fees to invoice.
 */
class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.paystack.secret_key' => FakePaymentGateway::SECRET]);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function webhook(string $event, array $data, ?string $signature = null)
    {
        $body = json_encode(['event' => $event, 'data' => $data]);

        return $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? FakePaymentGateway::sign($body),
        ], $body);
    }

    private function success(Payment $payment, array $overrides = []): array
    {
        return [
            'reference' => $payment->reference,
            'status' => 'success',
            'amount' => $payment->amountInCents(),
            'currency' => 'ZAR',
            'gateway_response' => 'Approved',
            ...$overrides,
        ];
    }

    public function test_a_top_up_goes_to_checkout_and_is_credited_once(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->create();

        $this->actingAs($user)->get(route('credit.index'))->assertInertia(fn (Assert $page) => $page
            ->component('credit/index')
            ->where('balance', 'R0.00')
            ->where('packs.1.value', '100.00'),
        );

        $this->post(route('credit.top-up'), ['amount' => '99'])->assertSessionHasErrors('amount');
        $this->post(route('credit.top-up'), ['amount' => '100.00'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame($user->email, $this->gateway->checkouts[0]['email']);
        $this->assertSame(route('credit.callback'), $this->gateway->checkouts[0]['callback']);

        // Coming back verifies the payment with Paystack and credits it.
        $this->get(route('credit.callback', ['reference' => $payment->reference]))->assertRedirect(route('credit.index'));
        $this->assertSame(PaymentStatus::Success, $payment->refresh()->status);
        $this->assertSame('100.00', app(Wallet::class)->balance($user->organisation));

        // The webhook for the same payment, and a second visit, change nothing.
        $this->webhook('charge.success', $this->success($payment))->assertOk();
        $this->get(route('credit.callback', ['reference' => $payment->reference]));

        $this->assertSame('100.00', app(Wallet::class)->balance($user->organisation));
        $this->assertSame(1, $user->organisation->creditTransactions()->count());
    }

    public function test_the_webhook_credits_a_top_up_on_its_own(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $payment = Payment::create(['organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'purpose' => 'top_up', 'amount' => '50.00', 'status' => 'pending']);

        $this->webhook('charge.success', $this->success($payment))->assertOk();
        $this->webhook('charge.success', $this->success($payment))->assertOk();

        $this->assertSame('50.00', app(Wallet::class)->balance($user->organisation));
        $this->assertSame(1, $user->organisation->creditTransactions()->count());
    }

    public function test_one_account_cannot_apply_another_accounts_payment_from_the_callback(): void
    {
        $owner = User::factory()->forKind(OrganisationKind::Household)->create();
        $payment = Payment::create(['organisation_id' => $owner->organisation_id, 'purpose' => 'top_up', 'amount' => '50.00', 'status' => 'pending']);

        $this->actingAs(User::factory()->forKind(OrganisationKind::Household)->create())
            ->get(route('credit.callback', ['reference' => $payment->reference]));

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_the_webhook_refuses_a_bad_signature_and_a_wrong_amount(): void
    {
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $payment = Payment::create(['organisation_id' => $user->organisation_id, 'purpose' => 'top_up', 'amount' => '250.00', 'status' => 'pending']);

        $this->webhook('charge.success', $this->success($payment), 'forged')->assertStatus(401);
        $this->webhook('charge.success', $this->success($payment, ['amount' => 2500]))->assertOk();
        $this->webhook('charge.success', $this->success($payment, ['currency' => 'NGN']))->assertOk();
        $this->webhook('transfer.success', ['reference' => $payment->reference])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame('0.00', app(Wallet::class)->balance($user->organisation));
    }

    public function test_a_paid_top_up_is_credited_without_the_webhook_or_the_customer_coming_back(): void
    {
        CarbonImmutable::setTestNow('2026-11-02 10:00:00');
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $payment = Payment::create(['organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'purpose' => 'top_up', 'amount' => '100.00', 'status' => 'pending']);

        // Too new to ask about: the customer is still on Paystack's page.
        CarbonImmutable::setTestNow('2026-11-02 10:01:00');
        $this->artisan('payments:reconcile')->expectsOutput('0 applied, 0 closed.');
        $this->assertSame([], $this->gateway->verified);

        CarbonImmutable::setTestNow('2026-11-02 10:05:00');
        $this->artisan('payments:reconcile')->expectsOutput('1 applied, 0 closed.');
        $this->artisan('payments:reconcile')->expectsOutput('0 applied, 0 closed.');

        $this->assertSame(PaymentStatus::Success, $payment->refresh()->status);
        $this->assertSame('100.00', app(Wallet::class)->balance($user->organisation));
        $this->assertSame([$payment->reference], $this->gateway->verified);
    }

    public function test_an_unpaid_checkout_is_asked_about_less_often_then_closed_after_two_days(): void
    {
        CarbonImmutable::setTestNow('2026-11-02 10:00:00');
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $payment = Payment::create(['organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'purpose' => 'top_up', 'amount' => '100.00', 'status' => 'pending']);
        $this->gateway->verifyStatus = 'abandoned';

        $runAt = function (string $time): void {
            CarbonImmutable::setTestNow($time);
            $this->artisan('payments:reconcile')->assertSuccessful();
        };

        $runAt('2026-11-02 10:30:00');    // under two hours old: every run
        $runAt('2026-11-02 13:20:00');    // older: only the first run of the hour
        $this->assertCount(1, $this->gateway->verified);

        $runAt('2026-11-02 14:00:00');
        $this->assertCount(2, $this->gateway->verified);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        CarbonImmutable::setTestNow('2026-11-04 10:20:00');
        $this->artisan('payments:reconcile')->expectsOutput('0 applied, 1 closed.');

        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);
        $this->assertSame('0.00', app(Wallet::class)->balance($user->organisation));
    }

    public function test_a_payment_paid_with_the_wrong_amount_is_never_credited_and_is_closed_after_two_days(): void
    {
        CarbonImmutable::setTestNow('2026-11-02 10:00:00');
        $user = User::factory()->forKind(OrganisationKind::Household)->create();
        $payment = Payment::create(['organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'purpose' => 'top_up', 'amount' => '100.00', 'status' => 'pending']);
        $this->gateway->verifyAmountInCents = 100;

        CarbonImmutable::setTestNow('2026-11-02 10:05:00');
        $this->artisan('payments:reconcile')->expectsOutput('0 applied, 0 closed.');
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        CarbonImmutable::setTestNow('2026-11-04 10:05:00');
        $this->artisan('payments:reconcile')->expectsOutput('0 applied, 1 closed.');
        $this->artisan('payments:reconcile')->expectsOutput('0 applied, 0 closed.');

        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);
        $this->assertStringContainsString('not the amount', $payment->gateway_message);
        $this->assertSame('0.00', app(Wallet::class)->balance($user->organisation));
    }

    public function test_staff_record_an_eft_once(): void
    {
        $staff = User::factory()->staff()->create();
        $household = User::factory()->forKind(OrganisationKind::Household)->create();

        $this->actingAs($household)->post(route('staff.organisations.eft', $household->organisation), ['amount' => '100', 'bank_reference' => 'X'])->assertForbidden();

        $this->actingAs($staff)->post(route('staff.organisations.eft', $household->organisation), ['amount' => '250', 'bank_reference' => 'FNB 7781'])->assertSessionHasNoErrors();
        $this->post(route('staff.organisations.eft', $household->organisation), ['amount' => '250', 'bank_reference' => 'fnb 7781']);

        $this->assertSame('250.00', app(Wallet::class)->balance($household->organisation));
        $this->assertSame($staff->id, $household->organisation->creditTransactions()->sole()->user_id);
    }
}
