<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return [
            'name' => 'Thandi M',
            'email' => 'thandi@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'popia_consent' => true,
            ...$overrides,
        ];
    }

    public function test_register_returns_a_token_and_the_user_with_consent_recorded(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registration(['device_name' => 'Pixel 9']));

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'popia_consent_version', 'popia_consented_at', 'ai_extraction_consent', 'created_at']])
            ->assertJsonPath('user.name', 'Thandi M')
            ->assertJsonPath('user.email', 'thandi@example.com')
            ->assertJsonPath('user.popia_consent_version', User::POPIA_CONSENT_VERSION)
            ->assertJsonPath('user.ai_extraction_consent', false);

        $this->assertSame(
            ['id', 'name', 'email', 'popia_consent_version', 'popia_consented_at', 'ai_extraction_consent', 'created_at'],
            array_keys($response->json('user')),
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $response->json('user.popia_consented_at'));

        $user = User::firstWhere('email', 'thandi@example.com');
        $this->assertSame('2026-10-v1', $user->popia_consent_version);
        $this->assertNotNull($user->popia_consented_at);
        $this->assertSame('Pixel 9', $user->tokens()->first()->name);

        $this->withToken($response->json('token'))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'thandi@example.com');
    }

    public function test_register_can_opt_in_to_ai_extraction(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['ai_extraction_consent' => true]))
            ->assertCreated()
            ->assertJsonPath('user.ai_extraction_consent', true);
    }

    public function test_popia_consent_is_required(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['popia_consent' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('popia_consent');

        $this->postJson('/api/v1/auth/register', $this->registration(['popia_consent' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('popia_consent');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_validates_password_and_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/register', $this->registration(['password_confirmation' => 'different-password']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        User::factory()->create(['email' => 'thandi@example.com']);
        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_returns_a_token(): void
    {
        User::factory()->create(['email' => 'thandi@example.com', 'password' => 'secret-password']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'Thandi@Example.com',
            'password' => 'secret-password',
            'device_name' => 'web',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']])
            ->assertJsonPath('user.email', 'thandi@example.com');
    }

    public function test_wrong_credentials_return_422_on_email(): void
    {
        User::factory()->create(['email' => 'thandi@example.com', 'password' => 'secret-password']);

        $this->postJson('/api/v1/auth/login', ['email' => 'thandi@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_and_register_are_throttled_to_six_per_minute_per_ip(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429);
        $this->postJson('/api/v1/auth/register', $this->registration())
            ->assertStatus(429);

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->registration())->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_requests_without_a_token_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        // Even without an Accept header the API answers in JSON.
        $this->get('/api/v1/dashboard')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_me_can_be_read_and_updated(): void
    {
        $user = $this->signIn(User::factory()->create(['name' => 'Old Name']));

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Old Name');

        $this->patchJson('/api/v1/me', ['name' => 'New Name', 'ai_extraction_consent' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.ai_extraction_consent', true);

        $this->patchJson('/api/v1/me', ['ai_extraction_consent' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ai_extraction_consent');

        $this->assertTrue($user->fresh()->ai_extraction_consent);
    }
}
