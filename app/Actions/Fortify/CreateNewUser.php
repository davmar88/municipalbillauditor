<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user. Their animal is made
     * after sign-up, on the first visit to the game.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return $this->createAccount($input['name'], $input['email'], $input['password']);
    }

    /**
     * The account. Google sign-ups come here too, with a random password
     * (they can set one with "Forgot password") and the email Google has
     * verified.
     *
     * @param  array<string, mixed>  $extra  Attributes set on the user directly.
     */
    public function createAccount(string $name, string $email, string $password, array $extra = []): User
    {
        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill($extra);
        $user->save();

        return $user;
    }
}
