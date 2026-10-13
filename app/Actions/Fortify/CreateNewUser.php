<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\OrganisationKind;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user, with every account they
     * are signing up for: a home, a rental portfolio, a business, or any mix.
     * They start in the first, and can switch and add more later.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $input = self::normalise($input);
        $types = $input['account_types'];

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'account_types' => ['required', 'array', 'min:1'],
            'account_types.*' => ['distinct', Rule::enum(OrganisationKind::class)],
            'organisation_names' => ['array'],
            'organisation_names.agent' => [Rule::requiredIf(in_array(OrganisationKind::Agent->value, $types, true)), 'nullable', 'string', 'max:255'],
            'organisation_names.business' => [Rule::requiredIf(in_array(OrganisationKind::Business->value, $types, true)), 'nullable', 'string', 'max:255'],
        ], [
            'account_types.required' => 'Choose at least one: your home, rental units or a business.',
            'organisation_names.agent.required' => 'Enter the name of your agency or portfolio.',
            'organisation_names.business.required' => 'Enter the name of the business or body corporate.',
        ])->validate();

        return $this->createAccounts(self::accounts($input), $input['name'], $input['email'], $input['password']);
    }

    /**
     * The accounts asked for, home first, then rentals, then a business. The
     * register page (and Google sign-up, through the same query) sends
     * account_types[] and organisation_names[kind]; an older single
     * account_type and organisation_name still work.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{kind: OrganisationKind, name: ?string}>
     */
    public static function accounts(array $input): array
    {
        $input = self::normalise($input);

        $accounts = [];
        foreach (OrganisationKind::cases() as $kind) {
            if (in_array($kind->value, $input['account_types'], true)) {
                $name = trim((string) ($input['organisation_names'][$kind->value] ?? ''));
                $accounts[] = ['kind' => $kind, 'name' => $kind === OrganisationKind::Household || $name === '' ? null : Str::limit($name, 255, '')];
            }
        }

        return $accounts === [] ? [['kind' => OrganisationKind::Household, 'name' => null]] : $accounts;
    }

    /**
     * The person and their accounts. An account without a name (a home, or
     * a Google sign-up that skipped it) takes the person's name. Google
     * sign-ups come here too, with a random password (they can set one with
     * "Forgot password") and the email Google has verified.
     *
     * @param  list<array{kind: OrganisationKind, name: ?string}>  $accounts
     * @param  array<string, mixed>  $extra  Attributes set on the user directly.
     */
    public function createAccounts(array $accounts, string $name, string $email, string $password, array $extra = []): User
    {
        return DB::transaction(function () use ($accounts, $name, $email, $password, $extra) {
            $organisations = array_map(fn (array $account) => Organisation::create([
                'kind' => $account['kind'],
                'name' => $account['name'] ?? $name,
            ]), $accounts);

            $user = new User([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
            $user->forceFill($extra);
            $user->organisation()->associate($organisations[0]);
            $user->save();
            $user->organisations()->syncWithoutDetaching(array_map(fn (Organisation $o) => $o->id, $organisations));

            return $user;
        });
    }

    /**
     * account_types as a list of strings, and organisation_names keyed by
     * kind, whichever form the input came in.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalise(array $input): array
    {
        $types = $input['account_types'] ?? [$input['account_type'] ?? OrganisationKind::Household->value];
        $types = array_values(array_filter(array_map('strval', is_array($types) ? $types : [$types])));
        $names = is_array($input['organisation_names'] ?? null) ? $input['organisation_names'] : [];

        if (count($types) === 1 && filled($input['organisation_name'] ?? null)) {
            $names[$types[0]] ??= (string) $input['organisation_name'];
        }

        return [...$input, 'account_types' => $types, 'organisation_names' => $names];
    }
}
