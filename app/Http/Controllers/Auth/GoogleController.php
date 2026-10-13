<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\CreateNewUser;
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
 * verified that email. Otherwise a new person is made, with every account
 * picked on the register page, or a household from the login page. People
 * with two-factor sign-in still get its challenge.
 */
class GoogleController extends Controller
{
    public function redirect(Request $request): SymfonyRedirectResponse|RedirectResponse
    {
        if (! filled(config('services.google.client_id'))) {
            return to_route('login')->withErrors(['email' => 'Google sign-in is not set up yet.']);
        }

        // The accounts the register page had picked, should this be a new
        // person: kind and name of each, as plain values for the session.
        $request->session()->put('google.signup', array_map(fn (array $account) => [
            'kind' => $account['kind']->value,
            'name' => $account['name'],
        ], CreateNewUser::accounts($request->query())));

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
            $user = $accounts->createAccounts(
                CreateNewUser::accounts([
                    'account_types' => array_column($signup, 'kind'),
                    'organisation_names' => array_column($signup, 'name', 'kind'),
                ]),
                (string) ($google->getName() ?: Str::before($email, '@')),
                $email,
                Str::password(40),
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
