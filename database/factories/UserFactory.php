<?php

namespace Database\Factories;

use App\Enums\CreditKind;
use App\Enums\OrganisationKind;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * A user signed up for a managing agent or business account.
     */
    public function forKind(OrganisationKind $kind): static
    {
        return $this->state(fn (array $attributes) => [
            'organisation_id' => Organisation::factory()->state(['kind' => $kind]),
        ]);
    }

    /**
     * Prepaid credit on the user's account, added through the ledger.
     */
    public function withCredit(string $amount = '100'): static
    {
        return $this->afterCreating(fn (User $user) => app(Wallet::class)->credit(
            $user->organisation,
            CreditKind::TopUp,
            $amount,
            "test:{$user->organisation_id}:".Str::random(8),
            'Test credit',
        ));
    }

    public function staff(): static
    {
        return $this->state(fn (array $attributes) => ['is_staff' => true]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
