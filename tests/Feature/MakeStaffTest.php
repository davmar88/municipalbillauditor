<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_user_becomes_staff_and_can_be_revoked(): void
    {
        $user = User::factory()->create(['email' => 'thandi@example.com']);

        $this->artisan('app:make-staff', ['email' => 'Thandi@Example.com '])
            ->expectsOutput('thandi@example.com is now staff.')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()->is_staff);

        $this->artisan('app:make-staff', ['email' => 'thandi@example.com', '--revoke' => true])
            ->expectsOutput('thandi@example.com is no longer staff.')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()->is_staff);
    }

    public function test_an_unverified_account_is_refused(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'thandi@example.com']);

        $this->artisan('app:make-staff', ['email' => 'thandi@example.com'])->assertFailed();

        $this->assertFalse($user->fresh()->is_staff);
    }

    public function test_an_unknown_email_is_refused(): void
    {
        $this->artisan('app:make-staff', ['email' => 'nobody@example.com'])
            ->expectsOutput('No account uses nobody@example.com. Register on the site first, then run this again.')
            ->assertFailed();
    }
}
