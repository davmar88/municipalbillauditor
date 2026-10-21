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
 * The owner's admin page: the PayPal, Google and Claude keys, stored encrypted and
 * never shown again, and the switches that open Pineapple sales and
 * cash-outs.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'paypal-client-secret-1234';

    private const CLAUDE_KEY = 'sk-ant-test-key';

    private const GOOGLE_SECRET = 'google-client-secret-5678';

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

    public function test_google_keys_are_saved_encrypted_and_the_page_shows_the_redirect_address(): void
    {
        config(['services.google.client_id' => 'server-client', 'services.google.redirect' => 'https://animalfarm.cc/auth/google/callback']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.googleFromServer', true)
            ->where('settings.googleClientSecret', null)
            ->where('settings.googleRedirect', 'https://animalfarm.cc/auth/google/callback'));

        $this->save($admin, ['google_client_id' => 'not-a-google-id'])->assertSessionHasErrors('google_client_id');

        $this->save($admin, ['google_client_id' => 'owner.apps.googleusercontent.com', 'google_client_secret' => self::GOOGLE_SECRET])->assertSessionHasNoErrors();

        $raw = DB::table('app_settings')->where('key', 'google_client_secret')->value('value');
        $this->assertNotSame(self::GOOGLE_SECRET, $raw);
        $this->assertSame(self::GOOGLE_SECRET, Crypt::decryptString($raw));

        $this->actingAs($admin)->get(route('admin'))
            ->assertDontSee(self::GOOGLE_SECRET)
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.googleClientId', 'owner.apps.googleusercontent.com')
                ->where('settings.googleClientSecret', '••••5678')
                ->where('settings.googleFromServer', false));

        // Saving again with the box left empty keeps the secret.
        $this->save($admin, ['google_client_id' => 'owner.apps.googleusercontent.com']);
        $this->assertSame(self::GOOGLE_SECRET, app(Settings::class)->get('google_client_secret'));

        $this->actingAs($admin)->delete(route('admin.settings.forget', 'google_client_secret'))->assertRedirect();
        $this->assertNull(app(Settings::class)->get('google_client_secret'));
    }

    public function test_the_google_test_button_asks_google_about_the_id_and_secret(): void
    {
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/token' => Http::sequence()
            ->push(['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'], 400)
            ->push(['error' => 'invalid_client', 'error_description' => 'The provided client secret is invalid.'], 401)]);
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.settings.test', 'google'))->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Add the Google client ID and secret first.']);

        $this->save($admin, ['google_client_id' => 'owner.apps.googleusercontent.com', 'google_client_secret' => self::GOOGLE_SECRET]);

        $this->actingAs($admin)->post(route('admin.settings.test', 'google'))->assertInertiaFlash('toast.message', 'Google accepted the key.');
        $this->actingAs($admin)->post(route('admin.settings.test', 'google'))->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Google said: The provided client secret is invalid.']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['client_id'] === 'owner.apps.googleusercontent.com'
            && $request['client_secret'] === self::GOOGLE_SECRET);
    }

    public function test_chat_translation_uses_the_key_saved_here(): void
    {
        config(['game.translator.api_key' => null]);
        $this->assertInstanceOf(NullTranslator::class, app(Translator::class));

        app(Settings::class)->put('anthropic_api_key', self::CLAUDE_KEY);

        $this->assertInstanceOf(ClaudeTranslator::class, app(Translator::class));
    }

    public function test_the_mailbox_saved_here_is_encrypted_and_used_for_mail(): void
    {
        $admin = $this->admin();

        $this->save($admin, ['mail_host' => 'mail.animalfarm.cc', 'mail_port' => '465', 'mail_username' => 'info@animalfarm.cc', 'mail_password' => 'not-a-real-one-9x2k'])->assertRedirect();

        $this->assertStringNotContainsString('not-a-real-one', (string) DB::table('app_settings')->where('key', 'mail_password')->value('value'));
        $this->actingAs($admin)->get(route('admin'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.mailHost', 'mail.animalfarm.cc')
            ->where('settings.mailPassword', '••••9x2k'));

        // The mail manager picks the mailbox up when it's first needed.
        app()->forgetInstance('mail.manager');
        app('mail.manager');
        $this->assertSame('mail.animalfarm.cc', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('info@animalfarm.cc', config('mail.from.address'));

        // A mail server must look like one.
        $this->save($admin, ['mail_host' => 'not a host!'])->assertSessionHasErrors('mail_host');
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
