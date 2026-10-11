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

        return DB::transaction(function () use ($input, $kind) {
            $organisation = Organisation::create([
                'kind' => $kind,
                'name' => $kind === OrganisationKind::Household ? $input['name'] : $input['organisation_name'],
            ]);

            $user = new User([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
            $user->organisation()->associate($organisation);
            $user->save();

            return $user;
        });
    }
}
