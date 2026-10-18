<?php

namespace Tests\Feature;

use App\Game\Translation\ClaudeTranslator;
use App\Game\Translation\NullTranslator;
use App\Game\Translation\Translator;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The owner's admin page: the PayPal and Claude keys, stored encrypted and
 * never shown again, and the switches that open Pineapple sales and
 * cash-outs.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'paypal-client-secret-1234';

    private const CLAUDE_KEY = 'sk-ant-test-key';

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    /** @param  array<string, mixed>  $changes */
    private function save(User $admin, array $changes = []): TestResponse
    {
        return $this->actingAs($admin)->put(route('admin.settings.update'), [
            'apples_on_sale' => '0',
            'cashout_open' => '0',
            'paypal_mode' => 'sandbox',
            ...$changes,
        ]);
    }

    public function test_only_admins_open_the_page_and_staff_are_not_enough(): void
    {
        $this->get(route('admin'))->assertRedirect(route('login'));

        $staff = User::factory()->create();
        $staff->forceFill(['is_staff' => true])->save();
        $this->actingAs($staff)->get(route('admin'))->assertForbidden();
        $this->actingAs($staff)->put(route('admin.settings.update'), ['apples_on_sale' => '1', 'paypal_mode' => 'live'])->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings')
            ->where('settings.applesOnSale', false)
            ->where('ready', false)
            ->missing('webhookUrl')
            ->missing('settings.paystackSecretKey'));
    }

    public function test_a_saved_key_is_encrypted_and_only_its_last_four_characters_come_back(): void
    {
        $admin = $this->admin();

        $this->save($admin, ['paypal_client_id' => 'client', 'paypal_client_secret' => self::SECRET])->assertSessionHasNoErrors();

        $raw = DB::table('app_settings')->where('key', 'paypal_client_secret')->value('value');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertSame(self::SECRET, Crypt::decryptString($raw));

        $this->actingAs($admin)->get(route('admin'))
            ->assertDontSee(self::SECRET)
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.paypalClientSecret', '••••1234')
                ->where('ready', true));

        // Saving again with the box left empty keeps the key.
        $this->save($admin, ['paypal_client_id' => 'client', 'paypal_client_secret' => '']);
        $this->assertSame(self::SECRET, app(Settings::class)->get('paypal_client_secret'));

        $this->actingAs($admin)->delete(route('admin.settings.forget', 'paypal_client_secret'))->assertRedirect();
        $this->assertNull(app(Settings::class)->get('paypal_client_secret'));
        $this->actingAs($admin)->delete('/admin/settings/paystack_secret_key')->assertNotFound();
    }

    public function test_keys_must_look_right_and_sales_need_paypal(): void
    {
        $admin = $this->admin();

        $this->save($admin, ['anthropic_api_key' => 'not-a-claude-key'])->assertSessionHasErrors('anthropic_api_key');
        $this->save($admin, ['apples_on_sale' => '1'])->assertSessionHasErrors(['apples_on_sale' => 'Add the PayPal keys before opening sales. Your keys were saved.']);
        $this->assertFalse(app(Settings::class)->on('apples_on_sale'));

        $this->save($admin, ['apples_on_sale' => '1', 'paypal_client_id' => 'client', 'paypal_client_secret' => 'secret', 'paypal_mode' => 'live'])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertTrue($settings->on('apples_on_sale'));
        $this->assertSame('live', $settings->get('paypal_mode'));
        $this->assertSame('client', $settings->get('paypal_client_id'));
    }

    public function test_cash_outs_stay_closed_until_the_owner_opens_them(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin'))->assertInertia(fn (Assert $page) => $page->where('settings.cashoutOpen', false));
        $this->actingAs($admin)->put(route('admin.settings.update'), ['apples_on_sale' => '0', 'paypal_mode' => 'sandbox'])->assertSessionHasErrors('cashout_open');

        $this->save($admin, ['cashout_open' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(app(Settings::class)->on('cashout_open'));
        $this->actingAs($admin)->get(route('admin'))->assertInertia(fn (Assert $page) => $page->where('settings.cashoutOpen', true));

        $this->save($admin)->assertSessionHasNoErrors();
        $this->assertFalse(app(Settings::class)->on('cashout_open'));
    }

    public function test_the_test_buttons_ask_paypal_and_claude(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401),
            'api.anthropic.com/v1/models*' => Http::response(['data' => []]),
        ]);
        $admin = $this->admin();
        $this->save($admin, [
            'paypal_client_id' => 'client',
            'paypal_client_secret' => 'wrong',
            'anthropic_api_key' => self::CLAUDE_KEY,
        ]);

        $this->actingAs($admin)->post('/admin/settings/test/paystack')->assertNotFound();
        $this->actingAs($admin)->post(route('admin.settings.test', 'paypal'))->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'PayPal said: Client Authentication failed']);
        $this->actingAs($admin)->post(route('admin.settings.test', 'claude'))->assertInertiaFlash('toast.message', 'Claude accepted the key.');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.anthropic.com/v1/models') && $request->hasHeader('x-api-key', self::CLAUDE_KEY));
    }

    public function test_chat_translation_uses_the_key_saved_here(): void
    {
        config(['game.translator.api_key' => null]);
        $this->assertInstanceOf(NullTranslator::class, app(Translator::class));

        app(Settings::class)->put('anthropic_api_key', self::CLAUDE_KEY);

        $this->assertInstanceOf(ClaudeTranslator::class, app(Translator::class));
    }

    public function test_a_verified_user_becomes_admin_from_the_server_only(): void
    {
        $user = User::factory()->create(['email' => 'spicy@example.com']);

        $this->artisan('app:make-admin', ['email' => 'Spicy@Example.com'])
            ->expectsOutput('spicy@example.com can now open the admin page.')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('app:make-admin', ['email' => 'spicy@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);

        $unverified = User::factory()->unverified()->create(['email' => 'new@example.com']);
        $this->artisan('app:make-admin', ['email' => 'new@example.com'])->assertFailed();
        $this->assertFalse($unverified->fresh()->is_admin);
    }
}
