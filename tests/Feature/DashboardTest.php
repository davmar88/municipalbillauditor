<?php

namespace Tests\Feature;

use App\Enums\OrganisationKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_each_kind_of_customer_starts_on_its_own_screen()
    {
        $starts = [
            'household' => route('bills.index'),
            'agent' => route('portfolio'),
            'business' => route('disputes.index'),
        ];

        foreach ($starts as $kind => $start) {
            $user = User::factory()->forKind(OrganisationKind::from($kind))->create();

            $this->actingAs($user)->get(route('dashboard'))->assertRedirect($start);
        }
    }
}
