<?php

namespace Tests\Feature\Game;

use App\Game\Apples;
use App\Game\GameException;
use App\Models\AppleEntry;
use App\Models\ApplePurchase;
use App\Models\Notice;
use App\Payments\Packs;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Apple packs bought with Paystack or PayPal, the webhook that confirms
 * Paystack payments, and swapping Apples for Corn.
 */
class ApplesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private const PAYSTACK_KEY = 'sk_test_0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        Http::preventStrayRequests();
    }

    private function openPaystack(): void
    {
        $settings = app(Settings::class);
        $settings->put('paystack_secret_key', self::PAYSTACK_KEY);
        $settings->put('apples_on_sale', '1');
    }

    private function openPayPal(): void
    {
        $settings = app(Settings::class);
        $settings->put('paypal_client_id', 'client-id');
        $settings->put('paypal_client_secret', 'client-secret');
        $settings->put('apples_on_sale', '1');
    }

    /** Paystack answering for whichever reference it is asked about. */
    private function fakePaystack(string $status = 'success', ?int $amount = null): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123'],
            ]),
            'api.paystack.co/transaction/verify/*' => function (Request $request) use ($status, $amount) {
                $reference = basename(parse_url($request->url(), PHP_URL_PATH));
                $purchase = ApplePurchase::query()->where('reference', $reference)->first();

                return Http::response(['status' => true, 'data' => [
                    'id' => 4099,
                    'status' => $status,
                    'reference' => $reference,
                    'amount' => $amount ?? $purchase?->amount,
                    'currency' => 'ZAR',
                ]]);
            },
        ]);
    }

    private function fakePayPal(string $capture = 'COMPLETED', string $value = '5.70'): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21-token', 'expires_in' => 32400]),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER-1',
                'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1'],
                    ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
                ],
            ], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-1/capture' => Http::response([
                'id' => 'ORDER-1',
                'status' => $capture === 'COMPLETED' ? 'COMPLETED' : 'PENDING',
                'purchase_units' => [['payments' => ['captures' => [[
                    'id' => 'CAPTURE-1',
                    'status' => $capture,
                    'amount' => ['currency_code' => 'USD', 'value' => $value],
                ]]]]],
            ], 201),
        ]);
    }

    public function test_bigger_packs_cost_less_per_apple(): void
    {
        $packs = collect(Packs::all())->keyBy('id');

        $this->assertSame(0, $packs['apples-50']['save']);
        $this->assertSame(5, $packs['apples-100']['save']);
        $this->assertSame(20, $packs['apples-1000']['save']);
    }

    public function test_the_apples_page_shows_the_packs_and_says_when_they_are_not_on_sale(): void
    {
        $player = $this->player();

        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->component('game/apples')
            ->has('packs', 5)
            ->where('onSale', false)
            ->where('ways.paystack', false)
            ->where('cornPerApple', 10));

        $this->openPaystack();

        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->where('onSale', true)
            ->where('ways.paystack', true)
            ->where('ways.paypal', false));
    }

    public function test_nothing_can_be_bought_before_sales_open(): void
    {
        $player = $this->player();
        app(Settings::class)->put('paystack_secret_key', self::PAYSTACK_KEY);

        $this->actingAs($player->user)
            ->post(route('play.apples.buy'), ['pack' => 'apples-100', 'via' => 'paystack'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', "Apple packs aren't on sale yet.");

        $this->assertSame(0, ApplePurchase::count());
        Http::assertNothingSent();
    }

    public function test_a_player_pays_with_paystack_and_gets_their_apples_once(): void
    {
        $this->openPaystack();
        $this->fakePaystack();
        $player = $this->player();

        $this->actingAs($player->user)
            ->post(route('play.apples.buy'), ['pack' => 'apples-100', 'via' => 'paystack'])
            ->assertRedirect('https://checkout.paystack.com/abc123');

        $purchase = ApplePurchase::sole();
        $this->assertSame(['apples-100', 100, 'ZAR', 9500, 'pending'], [$purchase->pack, $purchase->apples, $purchase->currency, $purchase->amount, $purchase->status]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->hasHeader('Authorization', 'Bearer '.self::PAYSTACK_KEY)
            && $request['amount'] === 9500
            && $request['currency'] === 'ZAR'
            && $request['email'] === $player->user->email
            && $request['reference'] === $purchase->reference);

        $this->actingAs($player->user)
            ->get(route('play.apples.paystack', ['reference' => $purchase->reference]))
            ->assertRedirect(route('play.apples'))
            ->assertInertiaFlash('toast.message', 'Your 100 Apples arrived. Thank you!');

        // Coming back twice, or the webhook arriving too, pays nothing more.
        $this->actingAs($player->user)->get(route('play.apples.paystack', ['reference' => $purchase->reference]));

        $this->assertSame(100, $player->fresh()->apples);
        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertSame('4099', $purchase->fresh()->provider_id);
        $this->assertSame(1, AppleEntry::query()->where('reference', "purchase:{$purchase->id}")->count());
        $this->assertStringContainsString('Your 100 Apples arrived', Notice::query()->where('player_id', $player->id)->value('text'));
    }

    public function test_the_signed_webhook_hands_over_apples_when_the_player_never_comes_back(): void
    {
        $this->openPaystack();
        $this->fakePaystack();
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'apples-250', 'via' => 'paystack']);
        $purchase = ApplePurchase::sole();

        $payload = json_encode(['event' => 'charge.success', 'data' => [
            'id' => 5100, 'status' => 'success', 'reference' => $purchase->reference, 'amount' => 22500, 'currency' => 'ZAR',
        ]]);

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'sk_test_wrong'),
        ], $payload)->assertStatus(401);
        $this->assertSame(0, $player->fresh()->apples, 'A webhook not signed with our key pays nothing.');

        foreach ([1, 2] as $time) {
            $this->call('POST', route('webhooks.paystack'), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, self::PAYSTACK_KEY),
            ], $payload)->assertOk();
        }

        $this->assertSame(250, $player->fresh()->apples, 'Paystack sending it twice pays once.');
        $this->assertSame('paid', $purchase->fresh()->status);
    }

    public function test_a_payment_that_does_not_match_its_pack_waits_for_the_owner(): void
    {
        $this->openPaystack();
        $this->fakePaystack(amount: 100);
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'apples-500', 'via' => 'paystack']);
        $purchase = ApplePurchase::sole();

        $this->actingAs($player->user)->get(route('play.apples.paystack', ['reference' => $purchase->reference]));

        $this->assertSame('review', $purchase->fresh()->status);
        $this->assertSame(0, $player->fresh()->apples);
    }

    public function test_a_failed_paystack_payment_hands_over_nothing(): void
    {
        $this->openPaystack();
        $this->fakePaystack('failed');
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'apples-50', 'via' => 'paystack']);
        $purchase = ApplePurchase::sole();

        $this->actingAs($player->user)
            ->get(route('play.apples.paystack', ['reference' => $purchase->reference]))
            ->assertInertiaFlash('toast.message', "The payment didn't go through, so nothing was charged.");

        $this->assertSame('failed', $purchase->fresh()->status);
        $this->assertSame(0, $player->fresh()->apples);
    }

    public function test_another_animal_cannot_settle_your_payment(): void
    {
        $this->openPaystack();
        $this->fakePaystack();
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'apples-50', 'via' => 'paystack']);
        $purchase = ApplePurchase::sole();
        $other = $this->player();

        $this->actingAs($other->user)
            ->get(route('play.apples.paystack', ['reference' => $purchase->reference]))
            ->assertInertiaFlash('toast.message', "We couldn't find that payment.");
        $this->actingAs($other->user)->post(route('play.apples.check', $purchase))->assertNotFound();

        $this->assertSame('pending', $purchase->fresh()->status);
    }

    public function test_a_player_pays_with_paypal_and_the_money_is_taken_when_they_come_back(): void
    {
        $this->openPayPal();
        $this->fakePayPal();
        $player = $this->player();

        $this->actingAs($player->user)
            ->post(route('play.apples.buy'), ['pack' => 'apples-100', 'via' => 'paypal'])
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

        $purchase = ApplePurchase::sole();
        $this->assertSame(['USD', 570, 'ORDER-1'], [$purchase->currency, $purchase->amount, $purchase->provider_id]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '5.70']
            && $request->hasHeader('PayPal-Request-Id', $purchase->reference));

        $this->actingAs($player->user)
            ->get(route('play.apples.paypal', ['token' => 'ORDER-1', 'PayerID' => 'PAYER']))
            ->assertRedirect(route('play.apples'))
            ->assertInertiaFlash('toast.message', 'Your 100 Apples arrived. Thank you!');

        $this->assertSame(100, $player->fresh()->apples);
        $this->assertSame('paid', $purchase->fresh()->status);
    }

    public function test_a_pending_paypal_capture_waits_and_a_cancel_charges_nothing(): void
    {
        $this->openPayPal();
        $this->fakePayPal('PENDING');
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'apples-100', 'via' => 'paypal']);

        $this->actingAs($player->user)
            ->get(route('play.apples.paypal', ['token' => 'ORDER-1']))
            ->assertInertiaFlash('toast.message', 'PayPal is still confirming the payment. Your Apples arrive as soon as it does.');
        $this->assertSame('pending', ApplePurchase::sole()->status);
        $this->assertSame(0, $player->fresh()->apples);

        $this->actingAs($player->user)
            ->get(route('play.apples.paypal-cancel', ['token' => 'ORDER-1']))
            ->assertInertiaFlash('toast.message', 'You left PayPal without paying. Nothing was charged.');
        $this->assertSame('failed', ApplePurchase::sole()->status);
    }

    public function test_apples_swap_for_corn_one_way(): void
    {
        $player = $this->player();
        Apples::award($player, 10, 'Test', 'test:swap');

        $this->actingAs($player->user)
            ->post(route('play.apples.swap'), ['apples' => 3])
            ->assertInertiaFlash('toast.message', 'You swapped 3 Apples for 30 Corn.');

        $player->refresh();
        $this->assertSame(7, $player->apples);
        $this->assertSame(100 + 30, $player->corn);
        $this->assertSame(7, (int) AppleEntry::query()->where('player_id', $player->id)->sum('amount'));

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('You have 7 Apples.');
        Apples::swapForCorn($player, 8);
    }
}
