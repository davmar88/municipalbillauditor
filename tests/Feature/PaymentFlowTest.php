<?php

namespace Tests\Feature;

use App\Enums\FeeStatus;
use App\Enums\OrganisationKind;
use App\Enums\PaymentStatus;
use App\Models\FeeInvoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\CustomerNotice;
use App\Services\Billing\Wallet;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

/**
 * Top-ups and monthly fees by card: checkout, Paystack's webhook, the
 * monthly invoice run, saved cards and EFT.
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

    public function test_monthly_fees_are_raised_once_and_the_first_one_saves_the_card(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 06:00:00');
        $agent = User::factory()->forKind(OrganisationKind::Agent)->create();
        $business = User::factory()->forKind(OrganisationKind::Business)->create();
        $business->organisation->update(['monthly_fee' => '1999.00']);
        User::factory()->forKind(OrganisationKind::Household)->create();

        $this->artisan('fees:invoice')->expectsOutput('2 fees raised.')->assertSuccessful();
        $this->artisan('fees:invoice')->expectsOutput('0 fees raised.')->assertSuccessful();

        $invoice = FeeInvoice::where('organisation_id', $agent->organisation_id)->sole();
        $this->assertSame('1500.00', $invoice->amount);
        $this->assertSame('2026-11-01', $invoice->period_month->toDateString());
        $this->assertSame('1999.00', FeeInvoice::where('organisation_id', $business->organisation_id)->sole()->amount);

        // No card yet: they get a link to pay.
        $this->assertSame([], $this->gateway->savedCardCharges);
        Notification::assertSentTo($agent, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'invoice_due');

        $this->actingAs($agent)->post(route('credit.invoices.pay', $invoice), [], ['X-Inertia' => 'true'])->assertStatus(409);
        $payment = Payment::where('fee_invoice_id', $invoice->id)->sole();

        $this->webhook('charge.success', $this->success($payment, [
            'authorization' => ['authorization_code' => 'AUTH_agent', 'reusable' => true, 'last4' => '4081', 'card_type' => 'visa', 'exp_month' => '12', 'exp_year' => '2030'],
            'customer' => ['email' => 'accounts@agent.example'],
        ]))->assertOk();

        $this->assertSame(FeeStatus::Paid, $invoice->refresh()->status);
        $organisation = $agent->organisation->refresh();
        $this->assertSame('AUTH_agent', $organisation->card_authorization);
        $this->assertSame('4081', $organisation->card_last4);
        $this->assertNotSame('AUTH_agent', $organisation->getRawOriginal('card_authorization'), 'The code is stored encrypted.');

        // Next month the saved card is charged, and Paystack's answer pays the invoice.
        CarbonImmutable::setTestNow('2026-12-01 06:00:00');
        $this->artisan('fees:invoice')->assertSuccessful();

        $december = FeeInvoice::where('organisation_id', $agent->organisation_id)->whereDate('period_month', '2026-12-01')->sole();
        $this->assertSame('AUTH_agent', $this->gateway->savedCardCharges[0]['code']);
        $this->assertSame('accounts@agent.example', $this->gateway->savedCardCharges[0]['email']);
        $this->assertSame(FeeStatus::Paid, $december->status);

        // The webhook for the same charge changes nothing.
        $charge = $this->gateway->savedCardCharges[0]['payment'];
        $this->webhook('charge.success', $this->success($charge))->assertOk();
        $this->assertSame(FeeStatus::Paid, $december->refresh()->status);
        $this->assertSame(PaymentStatus::Success, $charge->refresh()->status);
    }

    public function test_a_saved_card_charge_still_processing_is_settled_by_asking_paystack_later(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 06:00:00');
        $agent = User::factory()->forKind(OrganisationKind::Agent)->create();
        $agent->organisation->forceFill(['card_authorization' => 'AUTH_old', 'card_last4' => '1111', 'card_email' => 'a@agent.example'])->save();
        $this->gateway->savedCardStatus = 'pending';

        $this->artisan('fees:invoice')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, Payment::sole()->status);
        $this->assertSame(FeeStatus::Issued, FeeInvoice::sole()->status);
        Notification::assertNotSentTo($agent, CustomerNotice::class);

        CarbonImmutable::setTestNow('2026-11-01 06:05:00');
        $this->artisan('payments:reconcile')->expectsOutput('1 applied, 0 closed.')->assertSuccessful();

        $this->assertSame(FeeStatus::Paid, FeeInvoice::sole()->status);
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

    public function test_a_declined_saved_card_gets_a_link_to_pay(): void
    {
        $agent = User::factory()->forKind(OrganisationKind::Agent)->create();
        $agent->organisation->forceFill(['card_authorization' => 'AUTH_old', 'card_last4' => '1111', 'card_email' => 'a@agent.example'])->save();
        $this->gateway->savedCardStatus = 'failed';

        $this->artisan('fees:invoice')->assertSuccessful();

        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
        Notification::assertSentTo($agent, CustomerNotice::class, fn (CustomerNotice $n) => $n->kind === 'invoice_due'
            && str_contains($n->lines[0], 'ending 1111'));
        $this->assertSame(FeeStatus::Issued, FeeInvoice::sole()->status);
    }

    public function test_customers_can_remove_their_saved_card(): void
    {
        $agent = User::factory()->forKind(OrganisationKind::Agent)->create();
        $agent->organisation->forceFill(['card_authorization' => 'AUTH_old', 'card_last4' => '1111', 'card_email' => 'a@agent.example'])->save();

        $this->actingAs($agent)->get(route('account.edit'))->assertInertia(fn (Assert $page) => $page->where('account.card.last4', '1111'));
        $this->delete(route('account.card.destroy'))->assertRedirect();

        $this->assertNull($agent->organisation->refresh()->card_authorization);
        $this->assertNull($agent->organisation->card_last4);
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
