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

        $response->assertSessionHasErrors('organisation_name');
        $this->assertGuest();
    }
}
