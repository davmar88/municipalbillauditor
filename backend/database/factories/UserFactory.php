<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'popia_consent_version' => User::POPIA_CONSENT_VERSION,
            'popia_consented_at' => now(),
            'ai_extraction_consent' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function withAiConsent(): static
    {
        return $this->state(fn () => ['ai_extraction_consent' => true]);
    }
}
