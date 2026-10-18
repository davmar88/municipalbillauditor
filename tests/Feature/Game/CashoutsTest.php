<?php

namespace Tests\Feature\Game;

use App\Game\Apples;
use App\Models\AppleEntry;
use App\Models\Cashout;
use App\Models\Notice;
use App\Models\PayoutAccount;
use App\Models\Player;
use App\Models\User;
use App\Payments\Cashouts;
use App\Payments\Packs;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Cashing Apples out: payout details and the ID check, the limits, and the
 * owner paying each cash-out through Paystack or PayPal, without ever paying
 * one twice.
 */
class CashoutsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private const PAYSTACK_KEY = 'sk_test_0123456789abcdef';

    /** A valid South African ID number, for someone born on 1 January 1980. */
    private const ID_NUMBER = '8001015009087';

    /** What Paystack answers when a transfer is sent. */
    private mixed $transfer = null;

    /** What Paystack knows of a transfer: null for nothing. */
    private mixed $verify = null;

    private bool $accountMatches = true;

    /** What PayPal answers when a payout is sent. */
    private mixed $payout = null;

    private string $payoutStatus = 'PENDING';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        Http::preventStrayRequests();

        Http::fake([
            'api.paystack.co/bank/validate' => fn () => Http::response(['status' => true, 'message' => 'Personal Account Verification attempted', 'data' => ['verified' => $this->accountMatches]]),
            'api.paystack.co/bank?*' => Http::response(['status' => true, 'data' => [
                ['code' => '632005', 'name' => 'Absa Bank'],
                ['code' => '250655', 'name' => 'First National Bank'],
            ]]),
            'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_thandi']]),
            'api.paystack.co/transfer/verify/*' => fn (Request $request) => value($this->verify, $request) ?? Http::response(['status' => false, 'message' => 'Transfer not found', 'data' => null], 404),
            'api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_otp']]),
            'api.paystack.co/transfer/resend_otp' => Http::response(['status' => true, 'message' => 'OTP has been resent']),
            'api.paystack.co/transfer' => fn (Request $request) => value($this->transfer, $request),
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21-token', 'expires_in' => 32400]),
            'api-m.sandbox.paypal.com/v1/payments/payouts' => fn (Request $request) => value($this->payout, $request),
            'api-m.sandbox.paypal.com/v1/payments/payouts/*' => fn () => Http::response([
                'batch_header' => ['payout_batch_id' => 'BATCH1', 'batch_status' => 'PROCESSING'],
                'items' => [['transaction_status' => $this->payoutStatus, 'errors' => $this->payoutStatus === 'RETURNED' ? ['name' => 'RECEIVER_UNREGISTERED', 'message' => 'Nobody claimed it.'] : null]],
            ]),
        ]);
    }

    private function openCashouts(): void
    {
        $settings = app(Settings::class);
        $settings->put('paystack_secret_key', self::PAYSTACK_KEY);
        $settings->put('paypal_client_id', 'client-id');
        $settings->put('paypal_client_secret', 'client-secret');
        $settings->put('cashout_open', '1');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    /** An animal holding Apples it earned, not bought. */
    private function earner(int $apples = 2000): Player
    {
        $player = $this->player();
        Apples::award($player, $apples, 'Caught an outlaw', 'bounty:'.Str::uuid());

        return $player->fresh();
    }

    private function payoutTo(Player $player, string $method): PayoutAccount
    {
        return PayoutAccount::create([
            'player_id' => $player->id,
            'legal_name' => 'Thandi Mokoena',
            'document_type' => 'id',
            'document_number' => self::ID_NUMBER,
            'country' => 'ZA',
            'method' => $method,
            'paypal_email' => $method === 'paypal' ? 'thandi@example.com' : null,
            'bank_code' => $method === 'paystack' ? '632005' : null,
            'bank_name' => $method === 'paystack' ? 'Absa Bank' : null,
            'account_number' => $method === 'paystack' ? '4071234567' : null,
            'recipient_code' => $method === 'paystack' ? 'RCP_test123' : null,
            'verified_at' => $method === 'paystack' ? now() : null,
        ]);
    }

    /** An animal that asked to cash out 500 Apples, waiting for the owner. */
    private function waiting(string $method): Cashout
    {
        $player = $this->earner();
        $this->payoutTo($player, $method);

        return app(Cashouts::class)->request($player, 500);
    }

    /** @param  array<string, mixed>  $details */
    private function saveDetails(Player $player, array $details): TestResponse
    {
        return $this->actingAs($player->user)->post(route('play.cashout.account'), [
            'legal_name' => 'Thandi Mokoena',
            'country' => 'ZA',
            'document_type' => 'id',
            'document_number' => self::ID_NUMBER,
            'method' => 'paypal',
            'paypal_email' => 'thandi@example.com',
            'mine' => '1',
            ...$details,
        ]);
    }

    /** @param  array<string, mixed>  $body */
    private function webhook(array $body): TestResponse
    {
        $payload = (string) json_encode($body);

        return $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, self::PAYSTACK_KEY),
        ], $payload);
    }

    private function sent(string $path): int
    {
        return Http::recorded(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === $path)->count();
    }

    public function test_a_cash_out_always_pays_less_than_the_cheapest_apple_costs(): void
    {
        foreach (['ZAR' => 'zar', 'USD' => 'usd'] as $currency => $price) {
            // The cheapest Pineapple, swapped at the cheapest Apple pack's rate.
            $cheapest = collect(Packs::pineapples())->min(fn (array $pack) => $pack[$price] / $pack['pineapples'])
                * collect(Packs::apples())->min(fn (array $pack) => $pack['pineapples'] / $pack['apples']);

            $this->assertLessThan($cheapest, config("game.cashout.per_100.{$currency}") / 100, "A cash-out in {$currency} pays less than an Apple costs.");
        }

        $this->assertSame(30000, Cashouts::quote(500, 'ZAR'));
        $this->assertSame(1800, Cashouts::quote(500, 'USD'));
        $this->assertSame(356, Cashouts::quote(99, 'USD'), 'Rounded down, the game\'s way.');
    }

    public function test_cashing_out_stays_closed_until_the_owner_opens_it(): void
    {
        $player = $this->earner();
        $this->payoutTo($player, 'paypal');

        $this->actingAs($player->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page
            ->where('cashout.open', false)
            ->where('cashout.ways.paypal', false));

        $this->actingAs($player->user)->post(route('play.cashout.request'), ['apples' => 500])
            ->assertInertiaFlash('toast.message', "Cashing out isn't open yet.");

        $this->assertSame(0, Cashout::count());
        $this->assertSame(2000, $player->fresh()->apples);
    }

    public function test_animals_save_their_paypal_details_on_the_cash_out_page_before_it_opens(): void
    {
        $player = $this->earner();

        $this->saveDetails($player, [])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Saved.');
        $this->saveDetails($player, ['method' => 'paystack', 'paypal_email' => null, 'bank_code' => '632005', 'account_number' => '4071234567'])
            ->assertSessionHasErrors(['details' => 'Bank accounts can be added once cashing out opens. You can save your PayPal details now.']);
        $this->assertSame(0, $this->sent('/bank/validate'));

        $this->actingAs($player->user)->get(route('play.cashout'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('game/cashout')
            ->where('cashout.open', false)
            ->where('cashout.saveable.paypal', true)
            ->where('cashout.saveable.paystack', false)
            ->where('cashout.account.label', 'PayPal, t•••@example.com'));

        $this->actingAs($player->user)->post(route('play.cashout.request'), ['apples' => 500])
            ->assertInertiaFlash('toast.message', "Cashing out isn't open yet.");
        $this->assertSame(0, Cashout::count());
    }

    public function test_payout_details_need_an_adult_id_number_and_are_kept_encrypted(): void
    {
        $this->openCashouts();
        $player = $this->player();

        $this->saveDetails($player, ['document_number' => '8001015009088'])
            ->assertSessionHasErrors(['details' => "That isn't a South African ID number. Check its 13 digits."]);
        $this->saveDetails($player, ['document_number' => '1001015009084'])
            ->assertSessionHasErrors(['details' => 'Cashing out is for animals 18 and older.']);
        $this->saveDetails($player, ['country' => 'ZZ'])
            ->assertSessionHasErrors(['details' => 'A South African ID number is for animals living in South Africa. Use your passport number.']);
        $this->saveDetails($player, ['mine' => null])->assertSessionHasErrors('mine');
        $this->saveDetails($player, ['method' => 'bitcoin'])->assertSessionHasErrors('method');
        $this->assertSame(0, PayoutAccount::count());

        $this->saveDetails($player, ['document_number' => '800101 5009 087', 'paypal_email' => 'Thandi@Example.com'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Saved.');

        $account = PayoutAccount::sole();
        $this->assertSame(['paypal', 'thandi@example.com', self::ID_NUMBER], [$account->method, $account->paypal_email, $account->document_number]);
        $raw = DB::table('payout_accounts')->first();
        $this->assertStringNotContainsString('thandi', strtolower((string) $raw->paypal_email.$raw->legal_name));
        $this->assertStringNotContainsString(self::ID_NUMBER, (string) $raw->document_number);

        $this->actingAs($player->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page
            ->where('cashout.account.label', 'PayPal, t•••@example.com')
            ->where('cashout.account.document', '••••9087')
            ->missing('cashout.account.paypal_email'));

        // Someone living abroad uses a passport.
        $this->saveDetails($player, ['country' => 'ZZ', 'document_type' => 'passport', 'document_number' => 'a1234567', 'paypal_email' => 'thandi@example.org'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['paypal', 'A1234567', 'thandi@example.org'], [$account->fresh()->method, $account->fresh()->document_number, $account->fresh()->paypal_email]);
    }

    public function test_a_bank_account_is_checked_with_the_bank_once(): void
    {
        $this->openCashouts();
        $bank = ['method' => 'paystack', 'paypal_email' => null, 'bank_code' => '632005', 'account_number' => '4071234567'];

        // Each check costs the game R3, so it waits until there's something to cash out.
        $this->saveDetails($this->player(), $bank)
            ->assertSessionHasErrors(['details' => 'You can add a bank account once you have 500 Apples to cash out.']);
        $this->assertSame(0, $this->sent('/bank/validate'));

        $player = $this->earner();

        $this->actingAs($player->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page
            ->where('cashout.ways.paystack', true)
            ->has('cashout.banks', 2));

        $this->saveDetails($player, $bank)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Saved. The bank confirmed the account is yours.');

        $account = PayoutAccount::sole();
        $this->assertSame(['Absa Bank', 'RCP_thandi', 'Absa Bank, ••••4567'], [$account->bank_name, $account->recipient_code, $account->describe()]);
        $this->assertNotNull($account->verified_at);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/bank/validate'
            && $request['account_number'] === '4071234567'
            && $request['bank_code'] === '632005'
            && $request['account_name'] === 'Thandi Mokoena'
            && $request['document_type'] === 'identityNumber'
            && $request['document_number'] === self::ID_NUMBER);

        // Saving the same account again doesn't pay for another check.
        $this->saveDetails($player, $bank)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->sent('/bank/validate'));

        $this->accountMatches = false;
        $this->saveDetails($player, [...$bank, 'account_number' => '9999999999'])
            ->assertSessionHasErrors(['details' => "The bank says that account isn't in the name and ID number you gave. Check them, or pick another way to be paid."]);
        $this->assertSame('4071234567', $account->fresh()->account_number);

        $this->saveDetails($player, [...$bank, 'country' => 'ZZ', 'document_type' => 'passport', 'document_number' => 'A1234567'])
            ->assertSessionHasErrors(['details' => 'Bank transfers go to South African bank accounts. Pick PayPal instead.']);
    }

    public function test_asking_to_cash_out_takes_the_apples_within_the_limits(): void
    {
        $this->openCashouts();
        $player = $this->earner();
        $this->payoutTo($player, 'paystack');
        Apples::award($player, 300, 'Swapped 2,580 Pineapples', 'exchange:900');

        $ask = fn (int $apples) => $this->actingAs($player->user)->post(route('play.cashout.request'), ['apples' => $apples]);

        $ask(400)->assertInertiaFlash('toast.message', 'Cash out at least 500 Apples.');
        $ask(2100)->assertInertiaFlash('toast.message', '300 of your Apples were bought in the last 14 days, and bought Apples wait 14 days before they can be cashed out. You can cash out 2,000 now.');
        $ask(500)->assertInertiaFlash('toast.message', "You asked to cash out 500 Apples for R300.00. Our team checks every cash-out before it's sent, and we'll tell you when it's paid.");

        $cashout = Cashout::sole();
        $this->assertSame([500, 'paystack', 'ZAR', 30000, 'pending'], [$cashout->apples, $cashout->method, $cashout->currency, $cashout->amount, $cashout->status]);
        $this->assertSame(['name' => 'Thandi Mokoena', 'label' => 'Absa Bank, ••••4567', 'document' => 'SA ID ••••9087', 'recipient' => 'RCP_test123'], $cashout->destination);
        $this->assertSame(1800, $player->fresh()->apples);
        $this->assertSame(-500, AppleEntry::query()->where('reference', "cashout:{$cashout->reference}")->value('amount'));

        $ask(500)->assertInertiaFlash('toast.message', 'Your last cash-out is still on its way. Ask for the next one once it arrives.');

        // The daily cap counts what was cashed out today.
        $cashout->forceFill(['status' => 'paid'])->save();
        Apples::award($player, 5000, 'Caught an outlaw', 'bounty:'.Str::uuid());
        $ask(4600)->assertInertiaFlash('toast.message', 'You can cash out 5,000 Apples a day. You can cash out 4,500 more today.');

        $this->actingAs($player->fresh()->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page
            ->where('cashout.held', 300)
            ->where('cashout.available', 4500)
            ->where('cashout.history.0.status', 'paid'));

        // Bought Apples can be cashed out once 14 days have passed.
        $this->travel(15)->days();
        $this->assertSame(0, Cashouts::held($player->fresh()));
        $this->assertSame(5000, Cashouts::available($player->fresh()));
    }

    public function test_the_owner_pays_by_bank_transfer_once_and_the_animal_hears(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paystack');
        $admin = $this->admin();
        $this->transfer = Http::response(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_1', 'reference' => $cashout->reference]]);

        $this->actingAs($admin)->get(route('admin.cashouts'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/cashouts')
            ->has('queue', 1)
            ->where('queue.0.price', 'R300.00')
            ->where('queue.0.name', 'Thandi Mokoena')
            ->where('queue.0.sources.bounties', 2000)
            ->where('queue.0.paidBefore', 0)
            ->where('cashoutsWaiting', 1));

        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'Paid R300.00. We told the animal.');
        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'That cash-out was dealt with already.');

        $cashout->refresh();
        $this->assertSame(['paid', 'TRF_1', $admin->id], [$cashout->status, $cashout->provider_id, $cashout->reviewed_by]);
        $this->assertSame(1, $this->sent('/transfer'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/transfer'
            && $request->hasHeader('Authorization', 'Bearer '.self::PAYSTACK_KEY)
            && $request['amount'] === 30000
            && $request['currency'] === 'ZAR'
            && $request['recipient'] === 'RCP_test123'
            && $request['reference'] === $cashout->reference);
        $this->assertStringContainsString('Your cash-out was paid: R300.00 for 500 Apples, to Absa Bank, ••••4567.', Notice::query()->where('player_id', $cashout->player_id)->value('text'));
        $this->assertSame(1500, $cashout->player->fresh()->apples);
    }

    public function test_a_transfer_paystack_refuses_waits_for_the_owner_again(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paystack');
        $this->transfer = Http::response(['status' => false, 'message' => 'Your balance is not enough to fulfil this request'], 400);

        $this->actingAs($this->admin())->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'Nothing was sent. Paystack said: Your balance is not enough to fulfil this request');

        $cashout->refresh();
        $this->assertSame(['pending', 'Paystack said: Your balance is not enough to fulfil this request'], [$cashout->status, $cashout->note]);
        $this->assertSame(1500, $cashout->player->apples, 'The Apples wait with the cash-out.');
    }

    public function test_a_lost_answer_from_paystack_never_pays_twice(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paystack');
        $admin = $this->admin();

        // The transfer was sent, but the answer never came back.
        $this->transfer = Http::failedConnection();
        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', "Paystack didn't answer. Try again in a moment.");
        $this->assertSame('sending', $cashout->fresh()->status);

        // Paying again is refused as a reference Paystack has seen, and its record says it went.
        $this->verify = Http::response(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_9', 'reference' => $cashout->reference]]);
        $this->actingAs($admin)->post(route('admin.cashouts.check', $cashout))
            ->assertInertiaFlash('toast.message', 'Paid R300.00. We told the animal.');
        $this->assertSame(['paid', 'TRF_9'], [$cashout->fresh()->status, $cashout->fresh()->provider_id]);
        $this->assertSame(1, $this->sent('/transfer'));

        // And when Paystack has no record of one, nothing went, so it waits to be paid again.
        $second = $this->waiting('paystack');
        $this->actingAs($admin)->post(route('admin.cashouts.pay', $second));
        $this->verify = null;
        $this->actingAs($admin)->post(route('admin.cashouts.check', $second))
            ->assertInertiaFlash('toast.message', 'Nothing was sent. Paystack has no record of it, so nothing was sent. Press Pay to send it again.');
        $this->assertSame('pending', $second->fresh()->status);

        $this->transfer = Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400);
        $this->verify = Http::response(['status' => true, 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_10']]);
        $this->actingAs($admin)->post(route('admin.cashouts.pay', $second))
            ->assertInertiaFlash('toast.message', 'Paystack is sending it. Press Check to see when it arrives.');
        $this->assertSame(['sending', 'TRF_10'], [$second->fresh()->status, $second->fresh()->provider_id]);
    }

    public function test_a_failed_bank_transfer_gives_the_apples_back_once(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paystack');
        $this->transfer = Http::response(['status' => true, 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_2']]);

        $this->actingAs($this->admin())->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'Paystack is sending it. Press Check to see when it arrives.');
        $this->assertSame('sending', $cashout->fresh()->status);

        foreach ([1, 2] as $time) {
            $this->webhook(['event' => 'transfer.failed', 'data' => ['reference' => $cashout->reference, 'transfer_code' => 'TRF_2', 'status' => 'failed', 'reason' => 'Account closed']])->assertOk();
        }

        $cashout->refresh();
        $this->assertSame(['failed', 'Account closed'], [$cashout->status, $cashout->note]);
        $this->assertSame(2000, $cashout->player->apples, 'Paystack telling us twice gives the Apples back once.');
        $this->assertSame(1, AppleEntry::query()->where('reference', "cashout:{$cashout->reference}:back")->count());
        $this->assertStringContainsString("Your cash-out of R300.00 didn't go through, so your 500 Apples are back.", Notice::query()->where('player_id', $cashout->player_id)->latest('id')->value('text'));

        // A success after it came back pays out nothing more.
        $this->webhook(['event' => 'transfer.success', 'data' => ['reference' => $cashout->reference]])->assertOk();
        $this->assertSame('failed', $cashout->fresh()->status);
    }

    public function test_a_transfer_waiting_for_an_otp_is_sent_with_it(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paystack');
        $admin = $this->admin();
        $this->transfer = Http::response(['status' => true, 'data' => ['status' => 'otp', 'transfer_code' => 'TRF_otp']]);

        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', Cashouts::OTP_NOTE);
        $this->actingAs($admin)->get(route('admin.cashouts'))->assertInertia(fn (Assert $page) => $page
            ->where('queue.0.status', 'sending')
            ->where('queue.0.otp', true));

        $this->actingAs($admin)->post(route('admin.cashouts.resend-otp', $cashout))
            ->assertInertiaFlash('toast.message', 'Paystack is texting you a new OTP.');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/transfer/resend_otp'
            && $request['transfer_code'] === 'TRF_otp'
            && $request['reason'] === 'resend_otp');

        $this->actingAs($admin)->post(route('admin.cashouts.otp', $cashout), ['otp' => '12'])->assertSessionHasErrors('otp');
        $this->actingAs($admin)->post(route('admin.cashouts.otp', $cashout), ['otp' => '123456'])
            ->assertInertiaFlash('toast.message', 'Paid R300.00. We told the animal.');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.paystack.co/transfer/finalize_transfer'
            && $request['transfer_code'] === 'TRF_otp'
            && $request['otp'] === '123456');
        $this->assertSame('paid', $cashout->fresh()->status);
    }

    public function test_the_owner_pays_by_paypal_and_checks_it_arrived(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paypal');
        $admin = $this->admin();
        $this->assertSame(['USD', 1800, 'thandi@example.com'], [$cashout->currency, $cashout->amount, $cashout->destination['email']]);
        $this->payout = Http::response(['batch_header' => ['payout_batch_id' => 'BATCH1', 'batch_status' => 'PENDING']], 201);

        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'PayPal took it. Press Check to see when it arrives.');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api-m.sandbox.paypal.com/v1/payments/payouts'
            && $request['sender_batch_header']['sender_batch_id'] === $cashout->reference
            && $request['items'][0]['receiver'] === 'thandi@example.com'
            && $request['items'][0]['amount'] === ['value' => '18.00', 'currency' => 'USD']);
        $this->assertSame(['sending', 'BATCH1'], [$cashout->fresh()->status, $cashout->fresh()->provider_id]);

        $this->actingAs($admin)->post(route('admin.cashouts.check', $cashout))
            ->assertInertiaFlash('toast.message', 'PayPal is still sending it. Check again later.');

        $this->payoutStatus = 'SUCCESS';
        $this->actingAs($admin)->post(route('admin.cashouts.check', $cashout))
            ->assertInertiaFlash('toast.message', 'Paid $18.00. We told the animal.');
        $this->assertSame('paid', $cashout->fresh()->status);
    }

    public function test_paypal_never_pays_a_cash_out_twice_and_a_returned_one_comes_back(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paypal');
        $admin = $this->admin();

        $this->payout = Http::failedConnection();
        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', "PayPal didn't answer. Press Check to see whether the payout went.");
        $this->assertSame(['sending', null], [$cashout->fresh()->status, $cashout->fresh()->provider_id]);

        // Sent again with the same reference, PayPal points to the first payout.
        $this->payout = Http::response(['name' => 'USER_BUSINESS_ERROR', 'message' => 'User business error.', 'details' => [[
            'field' => 'SENDER_BATCH_ID',
            'issue' => 'Batch with given sender_batch_id already exists',
            'link' => [['href' => 'https://api-m.sandbox.paypal.com/v1/payments/payouts/BATCH1', 'rel' => 'self', 'method' => 'GET']],
        ]]], 400);
        $this->actingAs($admin)->post(route('admin.cashouts.check', $cashout))
            ->assertInertiaFlash('toast.message', 'PayPal took it. Press Check to see when it arrives.');
        $this->assertSame('BATCH1', $cashout->fresh()->provider_id);

        $this->payoutStatus = 'RETURNED';
        $this->actingAs($admin)->post(route('admin.cashouts.check', $cashout))
            ->assertInertiaFlash('toast.message', "It didn't go through, so the Apples went back to the animal. Nobody claimed it.");
        $this->assertSame(['failed', 2000], [$cashout->fresh()->status, $cashout->player->fresh()->apples]);
    }

    public function test_the_owner_turns_a_cash_out_down_and_the_apples_come_back(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paypal');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.cashouts.reject', $cashout), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.cashouts.reject', $cashout), ['reason' => 'The account behind these Apples was opened yesterday.'])
            ->assertInertiaFlash('toast.message', 'Turned down. The 500 Apples went back, and we told the animal why.');
        $this->actingAs($admin)->post(route('admin.cashouts.reject', $cashout), ['reason' => 'Again'])
            ->assertInertiaFlash('toast.message', 'That cash-out was dealt with already.');

        $player = $cashout->player->fresh();
        $this->assertSame(['rejected', 2000], [$cashout->fresh()->status, $player->apples]);
        $this->assertStringContainsString('They said: The account behind these Apples was opened yesterday.', Notice::query()->where('player_id', $player->id)->value('text'));

        $this->actingAs($player->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page
            ->where('cashout.history.0.status', 'rejected')
            ->where('cashout.history.0.reason', 'The account behind these Apples was opened yesterday.'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payouts'));
    }

    public function test_deleting_the_account_takes_the_payout_details_and_keeps_the_record_of_money_paid(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paypal');
        $cashout->forceFill(['status' => 'paid'])->save();

        $cashout->player->user->delete();

        $this->assertSame(0, PayoutAccount::count());
        $cashout->refresh();
        $this->assertSame([null, 'paid', 'Thandi Mokoena'], [$cashout->player_id, $cashout->status, $cashout->destination['name']]);
    }

    public function test_only_the_owner_sees_and_pays_cash_outs(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting('paystack');

        $this->get(route('admin.cashouts'))->assertRedirect(route('login'));

        $staff = User::factory()->create();
        $staff->forceFill(['is_staff' => true])->save();
        $this->actingAs($staff)->get(route('admin.cashouts'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.cashouts.pay', $cashout))->assertForbidden();
        $this->actingAs($cashout->player->user)->post(route('admin.cashouts.reject', $cashout), ['reason' => 'No thanks'])->assertForbidden();
        $this->actingAs($cashout->player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page->where('cashoutsWaiting', 0));

        $this->assertSame('pending', $cashout->fresh()->status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/transfer'));
    }
}
