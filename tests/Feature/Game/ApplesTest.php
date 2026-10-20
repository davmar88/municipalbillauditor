<?php

namespace Tests\Feature\Game;

use App\Game\Apples;
use App\Game\GameException;
use App\Game\Pineapples;
use App\Models\AppleEntry;
use App\Models\ApplePurchase;
use App\Models\Notice;
use App\Models\PineappleEntry;
use App\Payments\Packs;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pineapples bought with PayPal, swapping Pineapples for packs of Apples,
 * and swapping Apples for Corn.
 */
class ApplesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        Http::preventStrayRequests();
    }

    private function openPayPal(): void
    {
        $settings = app(Settings::class);
        $settings->put('paypal_client_id', 'client-id');
        $settings->put('paypal_client_secret', 'client-secret');
        $settings->put('apples_on_sale', '1');
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

    public function test_bigger_packs_cost_less(): void
    {
        $pineapples = collect(Packs::pineapples())->keyBy('id');
        $apples = collect(Packs::apples())->keyBy('id');

        $this->assertSame(0, $pineapples['pineapples-450']['save']);
        $this->assertSame(5, $pineapples['pineapples-900']['save']);
        $this->assertSame(12, $pineapples['pineapples-8200']['save']);
        $this->assertSame(0, $apples['apples-50']['save']);
        $this->assertSame(9, $apples['apples-1000']['save']);

        // Each money pack buys the Apple pack of the same rank.
        foreach (array_values($apples->all()) as $rank => $pack) {
            $this->assertGreaterThanOrEqual($pack['pineapples'], array_values($pineapples->all())[$rank]['pineapples']);
        }
    }

    public function test_the_apples_page_shows_the_packs_in_dollars_and_says_when_paying_is_not_open(): void
    {
        $player = $this->player();

        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->component('game/apples')
            ->has('pineapplePacks', 5)
            ->where('pineapplePacks.1.usd', 570)
            ->missing('pineapplePacks.1.zar')
            ->has('applePacks', 5)
            ->where('onSale', false)
            ->where('open', false)
            ->where('cornPerApple', 10));

        // On sale, but nobody can pay until the PayPal keys are in.
        app(Settings::class)->put('apples_on_sale', '1');
        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->where('onSale', true)
            ->where('open', false));

        $this->openPayPal();
        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->where('onSale', true)
            ->where('open', true));
    }

    public function test_nothing_can_be_bought_before_sales_open(): void
    {
        $player = $this->player();
        $settings = app(Settings::class);
        $settings->put('paypal_client_id', 'client-id');
        $settings->put('paypal_client_secret', 'client-secret');

        $this->actingAs($player->user)
            ->post(route('play.apples.buy'), ['pack' => 'pineapples-900'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', "Pineapples aren't on sale yet.");

        $settings->put('paypal_client_secret', null);
        $settings->put('apples_on_sale', '1');

        $this->actingAs($player->user)
            ->post(route('play.apples.buy'), ['pack' => 'pineapples-900'])
            ->assertInertiaFlash('toast.message', "Paying with PayPal isn't open yet.");

        $this->assertSame(0, ApplePurchase::count());
        Http::assertNothingSent();
    }

    public function test_a_player_pays_with_paypal_and_gets_their_pineapples_once(): void
    {
        $this->openPayPal();
        $this->fakePayPal();
        $player = $this->player();

        $this->actingAs($player->user)
            ->post(route('play.apples.buy'), ['pack' => 'pineapples-900'])
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

        $purchase = ApplePurchase::sole();
        $this->assertSame(['pineapples-900', 900, 'paypal', 'USD', 570, 'ORDER-1', 'pending'], [$purchase->pack, $purchase->pineapples, $purchase->provider, $purchase->currency, $purchase->amount, $purchase->provider_id, $purchase->status]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '5.70']
            && $request->hasHeader('PayPal-Request-Id', $purchase->reference));

        $this->actingAs($player->user)
            ->get(route('play.apples.paypal', ['token' => 'ORDER-1', 'PayerID' => 'PAYER']))
            ->assertRedirect(route('play.apples'))
            ->assertInertiaFlash('toast.message', 'Your 900 Pineapples arrived. Swap them for Apples below.');

        // Coming back twice, or asking again, pays nothing more.
        $this->actingAs($player->user)->get(route('play.apples.paypal', ['token' => 'ORDER-1']));
        $this->actingAs($player->user)->post(route('play.apples.check', $purchase));

        $this->assertSame([900, 0], [$player->fresh()->pineapples, $player->fresh()->apples]);
        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertSame(1, PineappleEntry::query()->where('reference', "purchase:{$purchase->id}")->count());
        $this->assertStringContainsString('Your 900 Pineapples arrived', Notice::query()->where('player_id', $player->id)->value('text'));
    }

    public function test_a_player_pays_with_paypals_buttons_without_leaving_the_page(): void
    {
        $this->openPayPal();
        $this->fakePayPal();
        $player = $this->player();

        $this->actingAs($player->user)->get(route('play.apples'))
            ->assertInertia(fn ($page) => $page->where('paypal.clientId', 'client-id')->where('paypal.currency', 'USD'));

        $this->actingAs($player->user)
            ->postJson(route('play.apples.order'), ['pack' => 'pineapples-900'])
            ->assertOk()
            ->assertExactJson(['id' => 'ORDER-1']);

        // No pages to come back to: PayPal's window closes over the game.
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
            && ! isset($request['payment_source']['paypal']['experience_context']['return_url']));

        $this->actingAs($player->user)
            ->postJson(route('play.apples.capture', 'ORDER-1'))
            ->assertOk()
            ->assertJson(['status' => 'paid', 'message' => 'Your 900 Pineapples arrived. Swap them for Apples below.']);

        $this->assertSame(900, $player->fresh()->pineapples);

        // Someone else's order is nobody's business.
        $this->actingAs($this->player()->user)->postJson(route('play.apples.capture', 'ORDER-1'))->assertNotFound();
    }

    public function test_closing_paypals_window_charges_nothing(): void
    {
        $this->openPayPal();
        $this->fakePayPal();
        $player = $this->player();

        $this->actingAs($player->user)->postJson(route('play.apples.order'), ['pack' => 'pineapples-900']);
        $this->actingAs($player->user)->postJson(route('play.apples.cancel', 'ORDER-1'))->assertOk();

        $this->assertSame('failed', ApplePurchase::sole()->status);
        $this->assertSame(0, $player->fresh()->pineapples);
        $this->actingAs($player->user)->postJson(route('play.apples.order'), ['pack' => 'nonsense'])->assertStatus(422)->assertJson(['message' => 'Pick one of the packs.']);
    }

    public function test_a_pending_paypal_capture_waits_and_a_cancel_charges_nothing(): void
    {
        $this->openPayPal();
        $this->fakePayPal('PENDING');
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'pineapples-900']);

        $this->actingAs($player->user)
            ->get(route('play.apples.paypal', ['token' => 'ORDER-1']))
            ->assertInertiaFlash('toast.message', 'PayPal is still confirming the payment. Your Pineapples arrive as soon as it does.');
        $this->assertSame('pending', ApplePurchase::sole()->status);
        $this->assertSame(0, $player->fresh()->pineapples);

        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->has('pending', 1)
            ->where('pending.0.price', '$5.70'));

        $this->actingAs($player->user)
            ->get(route('play.apples.paypal-cancel', ['token' => 'ORDER-1']))
            ->assertInertiaFlash('toast.message', 'You left PayPal without paying. Nothing was charged.');
        $this->assertSame('failed', ApplePurchase::sole()->status);
    }

    public function test_a_payment_that_does_not_match_its_pack_waits_for_the_owner(): void
    {
        $this->openPayPal();
        $this->fakePayPal(value: '5.70');
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'pineapples-4200']);
        $purchase = ApplePurchase::sole();

        $this->actingAs($player->user)->get(route('play.apples.paypal', ['token' => 'ORDER-1']));

        $this->assertSame('review', $purchase->fresh()->status);
        $this->assertSame(0, $player->fresh()->pineapples);
    }

    public function test_a_declined_paypal_payment_hands_over_nothing(): void
    {
        $this->openPayPal();
        $this->fakePayPal('DECLINED');
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'pineapples-900']);
        $purchase = ApplePurchase::sole();

        $this->actingAs($player->user)
            ->get(route('play.apples.paypal', ['token' => 'ORDER-1']))
            ->assertInertiaFlash('toast.message', "The payment didn't go through, so nothing was charged.");

        $this->assertSame('failed', $purchase->fresh()->status);
        $this->assertSame(0, $player->fresh()->pineapples);
    }

    public function test_another_animal_cannot_settle_your_payment(): void
    {
        $this->openPayPal();
        $this->fakePayPal();
        $player = $this->player();
        $this->actingAs($player->user)->post(route('play.apples.buy'), ['pack' => 'pineapples-450']);
        $purchase = ApplePurchase::sole();
        $other = $this->player();

        $this->actingAs($other->user)
            ->get(route('play.apples.paypal', ['token' => 'ORDER-1']))
            ->assertInertiaFlash('toast.message', "We couldn't find that payment.");
        $this->actingAs($other->user)->post(route('play.apples.check', $purchase))->assertNotFound();

        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertSame(0, $other->fresh()->pineapples);
    }

    public function test_paystack_is_gone_and_an_old_paystack_payment_is_left_to_the_owner(): void
    {
        $player = $this->player();
        $old = ApplePurchase::create([
            'player_id' => $player->id,
            'pack' => 'pineapples-900',
            'pineapples' => 900,
            'provider' => 'paystack',
            'currency' => 'ZAR',
            'amount' => 9500,
            'reference' => 'rg-old',
            'status' => 'pending',
        ]);

        $this->post('/webhooks/paystack', ['event' => 'charge.success', 'data' => ['reference' => 'rg-old']])->assertNotFound();
        $this->actingAs($player->user)->get('/play/apples/paystack?reference=rg-old')->assertNotFound();

        $this->actingAs($player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page->has('pending', 0));
        $this->actingAs($player->user)->post(route('play.apples.check', $old))->assertNotFound();

        $this->assertSame('R95.00', $old->price());
        $this->assertSame(['pending', 0], [$old->fresh()->status, $player->fresh()->pineapples]);
        Http::assertNothingSent();
    }

    public function test_pineapples_swap_for_packs_of_apples(): void
    {
        $player = $this->player();
        Pineapples::award($player, 1000, 'Bought 1,000 Pineapples', 'purchase:test');

        $this->actingAs($player->user)
            ->post(route('play.apples.exchange'), ['pack' => 'apples-100'])
            ->assertInertiaFlash('toast.message', 'You swapped 880 Pineapples for 100 Apples.');

        $player->refresh();
        $this->assertSame([120, 100], [$player->pineapples, $player->apples]);
        $this->assertSame(120, (int) PineappleEntry::query()->where('player_id', $player->id)->sum('amount'));
        $this->assertSame(100, (int) AppleEntry::query()->where('player_id', $player->id)->where('reference', 'like', 'exchange:%')->sum('amount'));

        $this->actingAs($player->user)
            ->post(route('play.apples.exchange'), ['pack' => 'apples-100'])
            ->assertInertiaFlash('toast.message', 'You have 120 Pineapples.');
        $this->assertSame([120, 100], [$player->fresh()->pineapples, $player->fresh()->apples]);
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
