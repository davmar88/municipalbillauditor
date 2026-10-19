<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * "Continue with Google" runs on the client ID and secret saved on the admin
 * page, once both are there, and otherwise on the ones in the server's .env.
 */
final class GoogleSignIn
{
    public function __construct(private Settings $settings) {}

    /**
     * Puts the admin page's keys where Socialite reads them, and says whether
     * Google sign-in can work.
     */
    public function configure(): bool
    {
        $id = $this->settings->get('google_client_id');
        $secret = $this->settings->get('google_client_secret');

        if ($id !== null && $secret !== null) {
            config(['services.google.client_id' => $id, 'services.google.client_secret' => $secret]);
        }

        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /** Null when Google accepts the client ID and secret, otherwise what it said. */
    public function test(): ?string
    {
        if (! $this->configure()) {
            return 'Add the Google client ID and secret first.';
        }

        try {
            // A made-up code. Google turns it down either way, but it checks
            // the client ID and secret first.
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'authorization_code',
                'code' => 'test',
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => config('services.google.redirect'),
            ]);
        } catch (ConnectionException) {
            return "Google didn't answer. Try again in a moment.";
        }

        return $response->json('error') === 'invalid_grant'
            ? null
            : 'Google said: '.($response->json('error_description') ?? $response->json('error') ?? "error {$response->status()}");
    }
}
