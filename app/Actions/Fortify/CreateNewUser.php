<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\OrganisationKind;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user, with the account
     * (household, managing agent or business) they are signing up for.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $kind = OrganisationKind::tryFrom($input['account_type'] ?? '') ?? OrganisationKind::Household;

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'account_type' => ['sometimes', Rule::enum(OrganisationKind::class)],
            'organisation_name' => [
                Rule::requiredIf($kind !== OrganisationKind::Household),
                'nullable',
                'string',
                'max:255',
            ],
        ], [
            'organisation_name.required' => $kind === OrganisationKind::Agent
                ? 'Enter the name of your agency or portfolio.'
                : 'Enter the name of the business or body corporate.',
        ])->validate();

        return $this->createAccount($kind, $input['name'], $input['email'], $input['password'], $input['organisation_name'] ?? null);
    }

    /**
     * The account and its first user. Google sign-ups come here too, with a
     * random password (they can set one with "Forgot password") and the
     * email Google has verified.
     *
     * @param  array<string, mixed>  $extra  Attributes set on the user directly.
     */
    public function createAccount(OrganisationKind $kind, string $name, string $email, string $password, ?string $organisationName = null, array $extra = []): User
    {
        return DB::transaction(function () use ($kind, $name, $email, $password, $organisationName, $extra) {
            $organisation = Organisation::create([
                'kind' => $kind,
                'name' => $kind === OrganisationKind::Household || blank($organisationName) ? $name : $organisationName,
            ]);

            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill($extra);
            $user->organisation()->associate($organisation);
            $user->save();

            return $user;
        });
    }
}
