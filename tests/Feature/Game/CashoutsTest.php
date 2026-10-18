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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Cashing Apples out to PayPal: payout details and the ID check, the limits,
 * and the owner paying each cash-out, without ever paying one twice.
 */
class CashoutsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    /** A valid South African ID number, for someone born on 1 January 1980. */
    private const ID_NUMBER = '8001015009087';

    /** What PayPal answers when a payout is sent. */
    private mixed $payout = null;

    private string $payoutStatus = 'PENDING';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        Http::preventStrayRequests();

        Http::fake([
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

    private function payoutTo(Player $player): PayoutAccount
    {
        return PayoutAccount::create([
            'player_id' => $player->id,
            'legal_name' => 'Thandi Mokoena',
            'document_type' => 'id',
            'document_number' => self::ID_NUMBER,
            'country' => 'ZA',
            'method' => 'paypal',
            'paypal_email' => 'thandi@example.com',
        ]);
    }

    /** An animal that asked to cash out 500 Apples, waiting for the owner. */
    private function waiting(): Cashout
    {
        $player = $this->earner();
        $this->payoutTo($player);

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
            'paypal_email' => 'thandi@example.com',
            'mine' => '1',
            ...$details,
        ]);
    }

    public function test_a_cash_out_always_pays_less_than_the_cheapest_apple_costs(): void
    {
        // The cheapest Pineapple, swapped at the cheapest Apple pack's rate.
        $cheapest = collect(Packs::pineapples())->min(fn (array $pack) => $pack['usd'] / $pack['pineapples'])
            * collect(Packs::apples())->min(fn (array $pack) => $pack['pineapples'] / $pack['apples']);

        $this->assertLessThan($cheapest, config('game.cashout.per_100') / 100, 'A cash-out pays less than an Apple costs.');
        $this->assertSame(1800, Cashouts::quote(500));
        $this->assertSame(356, Cashouts::quote(99), 'Rounded down, the game\'s way.');
    }

    public function test_cashing_out_stays_closed_until_the_owner_opens_it_with_the_paypal_keys_in(): void
    {
        $player = $this->earner();
        $this->payoutTo($player);

        $this->actingAs($player->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page
            ->where('cashout.open', false)
            ->where('cashout.per100', 360));

        $this->actingAs($player->user)->post(route('play.cashout.request'), ['apples' => 500])
            ->assertInertiaFlash('toast.message', "Cashing out isn't open yet.");

        // Opened, but PayPal can't pay anyone without its keys.
        app(Settings::class)->put('cashout_open', '1');
        $this->actingAs($player->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page->where('cashout.open', false));
        $this->actingAs($player->user)->post(route('play.cashout.request'), ['apples' => 500])
            ->assertInertiaFlash('toast.message', "Cashing out isn't open yet.");

        $this->assertSame(0, Cashout::count());
        $this->assertSame(2000, $player->fresh()->apples);

        $this->openCashouts();
        $this->actingAs($player->user)->get(route('play.cashout'))->assertInertia(fn (Assert $page) => $page->where('cashout.open', true));
    }

    public function test_animals_save_their_paypal_details_on_the_cash_out_page_before_it_opens(): void
    {
        $player = $this->earner();

        $this->saveDetails($player, [])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Saved.');

        $this->actingAs($player->user)->get(route('play.cashout'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('game/cashout')
            ->where('cashout.open', false)
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
        $this->saveDetails($player, ['paypal_email' => null])->assertSessionHasErrors(['paypal_email' => 'Add the email address of your PayPal account.']);
        $this->saveDetails($player, ['paypal_email' => 'thandi'])->assertSessionHasErrors('paypal_email');
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

    public function test_asking_to_cash_out_takes_the_apples_within_the_limits(): void
    {
        $this->openCashouts();
        $player = $this->earner();
        $this->payoutTo($player);
        Apples::award($player, 300, 'Swapped 2,580 Pineapples', 'exchange:900');

        $ask = fn (int $apples) => $this->actingAs($player->user)->post(route('play.cashout.request'), ['apples' => $apples]);

        $ask(400)->assertInertiaFlash('toast.message', 'Cash out at least 500 Apples.');
        $ask(2100)->assertInertiaFlash('toast.message', '300 of your Apples were bought in the last 14 days, and bought Apples wait 14 days before they can be cashed out. You can cash out 2,000 now.');
        $ask(500)->assertInertiaFlash('toast.message', "You asked to cash out 500 Apples for $18.00. Our team checks every cash-out before it's sent, and we'll tell you when it's paid.");

        $cashout = Cashout::sole();
        $this->assertSame([500, 'paypal', 'USD', 1800, 'pending'], [$cashout->apples, $cashout->method, $cashout->currency, $cashout->amount, $cashout->status]);
        $this->assertSame(['name' => 'Thandi Mokoena', 'label' => 'PayPal, t•••@example.com', 'document' => 'SA ID ••••9087', 'email' => 'thandi@example.com'], $cashout->destination);
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

    public function test_the_owner_pays_by_paypal_once_and_checks_it_arrived(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting();
        $admin = $this->admin();
        $this->assertSame(['USD', 1800, 'thandi@example.com'], [$cashout->currency, $cashout->amount, $cashout->destination['email']]);
        $this->payout = Http::response(['batch_header' => ['payout_batch_id' => 'BATCH1', 'batch_status' => 'PENDING']], 201);

        $this->actingAs($admin)->get(route('admin.cashouts'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/cashouts')
            ->where('ready', true)
            ->where('live', false)
            ->has('queue', 1)
            ->where('queue.0.price', '$18.00')
            ->where('queue.0.name', 'Thandi Mokoena')
            ->where('queue.0.email', 'thandi@example.com')
            ->where('queue.0.sources.bounties', 2000)
            ->where('queue.0.paidBefore', 0)
            ->where('cashoutsWaiting', 1));

        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'PayPal took it. Press Check to see when it arrives.');
        $this->actingAs($admin)->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'That cash-out was dealt with already.');
        $this->assertSame(1, Http::recorded(fn (Request $request) => $request->url() === 'https://api-m.sandbox.paypal.com/v1/payments/payouts')->count());
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

        $cashout->refresh();
        $this->assertSame(['paid', 'BATCH1', $admin->id], [$cashout->status, $cashout->provider_id, $cashout->reviewed_by]);
        $this->assertStringContainsString('Your cash-out was paid: $18.00 for 500 Apples, to PayPal, t•••@example.com.', Notice::query()->where('player_id', $cashout->player_id)->value('text'));
        $this->assertSame(1500, $cashout->player->fresh()->apples);
    }

    public function test_a_payout_paypal_refuses_waits_for_the_owner_again(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting();
        $this->payout = Http::response(['name' => 'INSUFFICIENT_FUNDS', 'message' => 'Sender does not have sufficient funds.', 'details' => [['issue' => 'INSUFFICIENT_FUNDS']]], 422);

        $this->actingAs($this->admin())->post(route('admin.cashouts.pay', $cashout))
            ->assertInertiaFlash('toast.message', 'Nothing was sent. PayPal said: INSUFFICIENT_FUNDS');

        $cashout->refresh();
        $this->assertSame(['pending', 'PayPal said: INSUFFICIENT_FUNDS'], [$cashout->status, $cashout->note]);
        $this->assertSame(1500, $cashout->player->apples, 'The Apples wait with the cash-out.');
    }

    public function test_paypal_never_pays_a_cash_out_twice_and_a_returned_one_comes_back(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting();
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
        $this->assertStringContainsString("Your cash-out of $18.00 didn't go through, so your 500 Apples are back.", Notice::query()->where('player_id', $cashout->player_id)->latest('id')->value('text'));

        // Checking again gives nothing more back.
        $this->actingAs($admin)->post(route('admin.cashouts.check', $cashout))
            ->assertInertiaFlash('toast.message', 'Only a cash-out on its way through PayPal can be checked.');
        $this->assertSame(2000, $cashout->player->fresh()->apples);
        $this->assertSame(1, AppleEntry::query()->where('reference', "cashout:{$cashout->reference}:back")->count());
    }

    public function test_the_owner_turns_a_cash_out_down_and_the_apples_come_back(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting();
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
        $cashout = $this->waiting();
        $cashout->forceFill(['status' => 'paid'])->save();

        $cashout->player->user->delete();

        $this->assertSame(0, PayoutAccount::count());
        $cashout->refresh();
        $this->assertSame([null, 'paid', 'Thandi Mokoena'], [$cashout->player_id, $cashout->status, $cashout->destination['name']]);
    }

    public function test_only_the_owner_sees_and_pays_cash_outs(): void
    {
        $this->openCashouts();
        $cashout = $this->waiting();

        $this->get(route('admin.cashouts'))->assertRedirect(route('login'));

        $staff = User::factory()->create();
        $staff->forceFill(['is_staff' => true])->save();
        $this->actingAs($staff)->get(route('admin.cashouts'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.cashouts.pay', $cashout))->assertForbidden();
        $this->actingAs($cashout->player->user)->post(route('admin.cashouts.reject', $cashout), ['reason' => 'No thanks'])->assertForbidden();
        $this->actingAs($cashout->player->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page->where('cashoutsWaiting', 0));

        $this->assertSame('pending', $cashout->fresh()->status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payouts'));
    }

    public function test_bank_transfers_are_gone(): void
    {
        $this->openCashouts();
        $player = $this->earner();

        // Bank details sent along are left out: the account is PayPal's.
        $this->saveDetails($player, ['method' => 'paystack', 'bank_code' => '632005', 'account_number' => '4071234567'])->assertSessionHasNoErrors();
        $this->assertSame(['paypal', 'thandi@example.com'], [PayoutAccount::sole()->method, PayoutAccount::sole()->paypal_email]);
        $this->assertFalse(Schema::hasColumn('payout_accounts', 'account_number'));

        $cashout = app(Cashouts::class)->request($player, 500);
        $admin = $this->admin();
        $this->actingAs($admin)->post("/admin/cashouts/{$cashout->id}/otp", ['otp' => '123456'])->assertNotFound();
        $this->actingAs($admin)->post("/admin/cashouts/{$cashout->id}/otp/resend")->assertNotFound();
        $this->assertSame('pending', $cashout->fresh()->status);
    }
}
