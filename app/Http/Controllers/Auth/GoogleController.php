<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\OrganisationKind;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * "Continue with Google", for signing in and signing up, as on Cable Elite.
 *
 * An account already linked to the Google account signs in. Otherwise an
 * account with the same email is linked, but only when Google says it has
 * verified that email. Otherwise a new account is made, of the type picked
 * on the register page, or a household from the login page. Accounts with
 * two-factor sign-in still get its challenge.
 */
class GoogleController extends Controller
{
    public function redirect(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        if (! filled(config('services.google.client_id'))) {
            return to_route('login')->withErrors(['email' => 'Google sign-in is not set up yet.']);
        }

        // What the register page had picked, should this be a new account.
        $request->session()->put('google.signup', [
            'kind' => (OrganisationKind::tryFrom((string) $request->query('account_type')) ?? OrganisationKind::Household)->value,
            'organisation_name' => Str::limit(trim((string) $request->query('organisation_name')), 255, ''),
        ]);

        return Socialite::driver('google')->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function callback(Request $request, CreateNewUser $accounts): RedirectResponse
    {
        if ($request->filled('error')) {
            return to_route('login')->withErrors(['email' => 'Google sign-in was cancelled.']);
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed', ['error' => $e->getMessage()]);

            return to_route('login')->withErrors(['email' => "Google sign-in didn't finish. Please try again."]);
        }

        $email = Str::lower(trim((string) $google->getEmail()));
        $verified = $google instanceof AbstractUser && ($google->getRaw()['email_verified'] ?? false) === true;

        if ($email === '' || ! $verified) {
            return to_route('login')->withErrors(['email' => "Google hasn't verified the email address on that account."]);
        }

        $user = User::query()->where('google_id', $google->getId())->first()
            ?? User::query()->where('email', $email)->first();
        $signup = (array) $request->session()->pull('google.signup', []);

        if ($user === null) {
            $user = $accounts->createAccount(
                OrganisationKind::tryFrom((string) ($signup['kind'] ?? '')) ?? OrganisationKind::Household,
                (string) ($google->getName() ?: Str::before($email, '@')),
                $email,
                Str::password(40),
                $signup['organisation_name'] ?? null,
                ['google_id' => $google->getId(), 'email_verified_at' => now()],
            );
            event(new Registered($user));
        } else {
            $user->forceFill([
                'google_id' => $google->getId(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        // The same challenge Fortify sets after a correct password.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return to_route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }
}
