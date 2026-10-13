<?php

namespace Tests\Feature;

use App\Enums\OrganisationKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

/**
 * "Continue with Google": signing in, signing up, linking an existing
 * account, and the cases that must not sign anyone in.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    private function googleSays(string $email = 'thandi@gmail.com', bool $verified = true): void
    {
        $user = (new GoogleUser)
            ->setRaw(['sub' => 'google-123', 'email' => $email, 'email_verified' => $verified, 'name' => 'Thandi Nkosi'])
            ->map(['id' => 'google-123', 'name' => 'Thandi Nkosi', 'email' => $email]);

        Socialite::fake('google', $user);
    }

    public function test_the_button_shows_only_once_google_is_set_up(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('googleAvailable', true));
        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('googleAvailable', true));

        config(['services.google.client_id' => null]);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('googleAvailable', false));
        $this->get(route('google.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
    }

    public function test_a_new_google_user_gets_a_verified_household_account(): void
    {
        $this->googleSays();

        $this->get(route('google.redirect'))->assertRedirect();
        $this->get(route('google.callback'))->assertRedirect(config('fortify.home'));

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('thandi@gmail.com', $user->email);
        $this->assertSame('google-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(OrganisationKind::Household, $user->organisation->kind);
        $this->assertSame('Thandi Nkosi', $user->organisation->name);
        $this->assertNotNull($user->organisation->forwarding_code);
    }

    public function test_the_account_type_picked_on_the_register_page_carries_through(): void
    {
        $this->googleSays('accounts@acme-rentals.co.za');

        $this->get(route('google.redirect', ['account_type' => 'agent', 'organisation_name' => 'Acme Rentals']));
        $this->get(route('google.callback'));

        $organisation = User::sole()->organisation;
        $this->assertSame(OrganisationKind::Agent, $organisation->kind);
        $this->assertSame('Acme Rentals', $organisation->name);
    }

    public function test_an_existing_account_is_linked_when_google_has_verified_the_email(): void
    {
        $user = User::factory()->create(['email' => 'thandi@gmail.com']);
        $this->googleSays('Thandi@Gmail.com');

        $this->get(route('google.callback'))->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertSame(1, User::count());
    }

    public function test_an_email_google_has_not_verified_signs_no_one_in(): void
    {
        $user = User::factory()->create(['email' => 'thandi@example.com']);
        $this->googleSays('thandi@example.com', verified: false);

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
        $this->assertSame(1, User::count());
    }

    public function test_two_factor_sign_in_still_asks_for_the_code(): void
    {
        $user = User::factory()->withTwoFactor()->create(['email' => 'thandi@gmail.com']);
        $this->googleSays();

        $this->get(route('google.callback'))->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_cancelling_at_google_goes_back_to_the_login_page(): void
    {
        $this->get(route('google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_google_id_never_reaches_the_browser(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_id' => 'google-123'])->save();

        $this->assertArrayNotHasKey('google_id', $user->fresh()->toArray());
    }
}
