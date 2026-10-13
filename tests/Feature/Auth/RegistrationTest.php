<?php

namespace Tests\Feature\Auth;

use App\Enums\OrganisationKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::firstWhere('email', 'test@example.com');
        $this->assertSame(OrganisationKind::Household, $user->organisation->kind);
        $this->assertSame('Test User', $user->organisation->name);
    }

    public function test_managing_agents_register_with_their_agency_name()
    {
        $this->post(route('register.store'), [
            'account_type' => 'agent',
            'organisation_name' => 'Jacaranda Property Management',
            'name' => 'Pieter van Wyk',
            'email' => 'agent@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::firstWhere('email', 'agent@example.com');
        $this->assertSame(OrganisationKind::Agent, $user->organisation->kind);
        $this->assertSame('Jacaranda Property Management', $user->organisation->name);
    }

    public function test_businesses_must_name_the_business()
    {
        $response = $this->post(route('register.store'), [
            'account_type' => 'business',
            'name' => 'Naledi Dlamini',
            'email' => 'business@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('organisation_names.business');
        $this->assertGuest();
    }

    public function test_one_sign_up_can_make_a_home_a_portfolio_and_a_business()
    {
        $this->post(route('register.store'), [
            'account_types' => ['business', 'household', 'agent'],
            'organisation_names' => ['agent' => 'Jacaranda Rentals', 'business' => 'Protea Heights Body Corporate'],
            'name' => 'Naledi Dlamini',
            'email' => 'naledi@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::firstWhere('email', 'naledi@example.com');
        $accounts = $user->organisations;

        $this->assertSame(['household', 'agent', 'business'], $accounts->map(fn ($o) => $o->kind->value)->all());
        $this->assertSame(['Naledi Dlamini', 'Jacaranda Rentals', 'Protea Heights Body Corporate'], $accounts->pluck('name')->all());
        // Home first: that's where they start.
        $this->assertSame($accounts->first()->id, $user->organisation_id);
        $this->assertCount(3, array_unique($accounts->pluck('forwarding_code')->all()));
    }

    public function test_each_chosen_portfolio_or_business_needs_a_name_and_one_type_is_needed()
    {
        $this->post(route('register.store'), [
            'account_types' => ['household', 'agent'],
            'name' => 'Pieter van Wyk',
            'email' => 'pieter@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('organisation_names.agent')->assertSessionDoesntHaveErrors('organisation_names.business');

        $this->post(route('register.store'), [
            'account_types' => ['nonsense'],
            'name' => 'Pieter van Wyk',
            'email' => 'pieter@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('account_types.0');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
