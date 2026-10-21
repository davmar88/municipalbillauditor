<?php

namespace App\Http\Controllers\Admin;

use App\Game\ValleyAnimals;
use App\Http\Controllers\Controller;
use App\Models\AppleEntry;
use App\Models\ApplePurchase;
use App\Models\Cashout;
use App\Models\Faction;
use App\Models\Player;
use App\Models\User;
use App\Models\Warrant;
use App\Payments\PayPal;
use App\Support\GoogleSignIn;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The owner's page: the PayPal, Google and Claude keys, whether Pineapple
 * packs are on sale and cash-outs open, and what the sales brought in. Saved
 * keys are never sent back to the browser, only their last four characters.
 */
class SettingsController extends Controller
{
    /** Keys the owner can remove. */
    public const REMOVABLE = ['paypal_client_id', 'paypal_client_secret', 'google_client_id', 'google_client_secret', 'anthropic_api_key', 'mail_password'];

    public function index(Settings $settings, PayPal $paypal): Response
    {
        $paid = ApplePurchase::query()->where('status', 'paid');
        $sold = (int) (clone $paid)->sum('pineapples');
        // Apples handed over for Pineapples, swapped or grown on trees.
        $swapped = (int) AppleEntry::query()->where(fn ($query) => $query->where('reference', 'like', 'exchange:%')->orWhere('reference', 'like', 'tree:%'))->sum('amount');
        $held = (int) Player::query()->sum('apples') + (int) Faction::query()->sum('apples') + (int) Warrant::query()->where('status', 'open')->sum('bounty')
            + (int) Cashout::query()->whereIn('status', ['pending', 'sending'])->sum('apples');
        $cashedOut = (int) Cashout::query()->where('status', 'paid')->sum('apples');

        return Inertia::render('admin/settings', [
            'settings' => [
                'applesOnSale' => $settings->on('apples_on_sale'),
                'cashoutOpen' => $settings->on('cashout_open'),
                'paypalClientId' => $settings->get('paypal_client_id'),
                'paypalClientSecret' => $settings->hint('paypal_client_secret'),
                'paypalMode' => $settings->get('paypal_mode', 'sandbox'),
                'googleClientId' => $settings->get('google_client_id'),
                'googleClientSecret' => $settings->hint('google_client_secret'),
                'googleFromServer' => ($settings->get('google_client_id') === null || $settings->get('google_client_secret') === null)
                    && filled(config('services.google.client_id')),
                // The address the Google client must list as a redirect URI.
                'googleRedirect' => config('services.google.redirect'),
                'anthropicApiKey' => $settings->hint('anthropic_api_key'),
                'anthropicFromServer' => $settings->get('anthropic_api_key') === null && filled(config('game.translator.api_key')),
                'mailHost' => $settings->get('mail_host'),
                'mailPort' => $settings->get('mail_port', '465'),
                'mailUsername' => $settings->get('mail_username'),
                'mailPassword' => $settings->hint('mail_password'),
                'mailFromAddress' => $settings->get('mail_from_address'),
                // Until a mailbox is saved here, mail goes through the server's .env.
                'mailFromServer' => $settings->get('mail_host') === null ? config('mail.mailers.smtp.host') : null,
                // The animals the game runs itself, until there are enough real players.
                'valleyAnimals' => [
                    'on' => ValleyAnimals::on(),
                    'count' => Player::query()->where('npc', true)->count(),
                    'inFactions' => Player::query()->where('npc', true)->whereNotNull('faction_id')->count(),
                ],
            ],
            'ready' => $paypal->ready(),
            'sales' => [
                'count' => (clone $paid)->count(),
                'usd' => (int) (clone $paid)->where('currency', 'USD')->sum('amount'),
                'sold' => $sold,
                'unswapped' => (int) Player::query()->sum('pineapples'),
                'swapped' => $swapped,
                'held' => $held,
                'cashedOut' => $cashedOut,
                'kept' => max(0, $swapped - $held - $cashedOut),
                'review' => ApplePurchase::query()->where('status', 'review')->count(),
                'members' => Player::query()->where('orchard_until', '>', now())->count(),
            ],
            'purchases' => ApplePurchase::query()
                ->with('player:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ApplePurchase $purchase) => [
                    'id' => $purchase->id,
                    'animal' => $purchase->player?->name ?? 'A deleted account',
                    'pineapples' => $purchase->pineapples,
                    'price' => $purchase->price(),
                    'reference' => $purchase->reference,
                    'status' => $purchase->status,
                    'at' => $purchase->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function update(Request $request, Settings $settings, PayPal $paypal): RedirectResponse
    {
        $data = $request->validate([
            'apples_on_sale' => ['required', 'boolean'],
            'cashout_open' => ['required', 'boolean'],
            'paypal_client_id' => ['nullable', 'string', 'max:200'],
            'paypal_client_secret' => ['nullable', 'string', 'max:200'],
            'paypal_mode' => ['required', Rule::in(['sandbox', 'live'])],
            'google_client_id' => ['nullable', 'string', 'max:200', 'ends_with:.apps.googleusercontent.com'],
            'google_client_secret' => ['nullable', 'string', 'max:200'],
            'anthropic_api_key' => ['nullable', 'string', 'max:300', 'starts_with:sk-ant-'],
            'mail_host' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9.-]+$/'],
            'mail_port' => ['nullable', Rule::in(['465', '587', '25', '2525'])],
            'mail_username' => ['nullable', 'string', 'max:200'],
            'mail_password' => ['nullable', 'string', 'max:300'],
            'mail_from_address' => ['nullable', 'email', 'max:200'],
        ], [
            'mail_host.regex' => 'A mail server is a name like smtp.animalfarm.cc.',
            'google_client_id.ends_with' => 'A Google client ID ends with .apps.googleusercontent.com.',
            'anthropic_api_key.starts_with' => 'A Claude API key starts with sk-ant-.',
        ]);

        $user = $request->user();

        // A secret left empty keeps the one saved.
        foreach (['paypal_client_secret', 'google_client_secret', 'anthropic_api_key', 'mail_password'] as $key) {
            if (filled($data[$key] ?? null)) {
                $settings->put($key, $data[$key], $user);
            }
        }

        $settings->put('paypal_client_id', $data['paypal_client_id'] ?? null, $user);
        $settings->put('paypal_mode', $data['paypal_mode'], $user);
        $settings->put('google_client_id', $data['google_client_id'] ?? null, $user);
        $mailBefore = [$settings->get('mail_host'), $settings->get('mail_port'), $settings->get('mail_username'), $settings->get('mail_from_address')];
        $settings->put('mail_host', $data['mail_host'] ?? null, $user);
        $settings->put('mail_port', $data['mail_port'] ?? null, $user);
        $settings->put('mail_username', $data['mail_username'] ?? null, $user);
        $settings->put('mail_from_address', $data['mail_from_address'] ?? null, $user);

        // The queue worker reads the mailbox when it starts: a new one means a fresh worker.
        if (filled($data['mail_password'] ?? null) || $mailBefore !== [$settings->get('mail_host'), $settings->get('mail_port'), $settings->get('mail_username'), $settings->get('mail_from_address')]) {
            Artisan::call('queue:restart');
        }

        if ($request->boolean('apples_on_sale') && ! $paypal->ready()) {
            throw ValidationException::withMessages(['apples_on_sale' => 'Add the PayPal keys before opening sales. Your keys were saved.']);
        }

        $settings->put('apples_on_sale', $request->boolean('apples_on_sale') ? '1' : null, $user);
        $settings->put('cashout_open', $request->boolean('cashout_open') ? '1' : null, $user);

        return $this->told('Saved.');
    }

    /** Switch the valley animals on or off. */
    public function valleyAnimals(Request $request, ValleyAnimals $animals): RedirectResponse
    {
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        $animals->switch($on, $request->user());

        return $this->told($on
            ? 'The valley animals are back at work.'
            : 'The valley animals have gone: they left their factions and the map. Real players have the valley to themselves.');
    }

    public function forget(Request $request, string $key, Settings $settings): RedirectResponse
    {
        abort_unless(in_array($key, self::REMOVABLE, true), 404);
        $settings->put($key, null, $request->user());

        return $this->told('Removed.');
    }

    public function test(string $service, Settings $settings, PayPal $paypal, GoogleSignIn $google): RedirectResponse
    {
        $problem = match ($service) {
            'paypal' => $paypal->test(),
            'google' => $google->test(),
            'claude' => $this->testClaude($settings->get('anthropic_api_key', config('game.translator.api_key'))),
            'mail' => $this->testMail(request()->user()),
            default => abort(404),
        };

        if ($service === 'mail') {
            return $problem === null
                ? $this->told('A test mail is on its way to '.request()->user()->email.'.')
                : $this->told($problem, 'error');
        }

        $name = ['paypal' => 'PayPal', 'google' => 'Google', 'claude' => 'Claude'][$service];

        return $problem === null
            ? $this->told("{$name} accepted the key.")
            : $this->told($problem, 'error');
    }

    /** Null when a test mail to the owner went out, otherwise what the mail server said. */
    private function testMail(User $user): ?string
    {
        try {
            Mail::raw('This is a test from the Animal Farm admin page. If you can read it, the game can send mail.', function ($message) use ($user) {
                $message->to($user->email)->subject('Animal Farm mail test');
            });
        } catch (Throwable $exception) {
            return 'The mail server said: '.Str::limit($exception->getMessage(), 240);
        }

        return null;
    }

    /** Null when Claude accepts the key, otherwise what it said. */
    private function testClaude(?string $key): ?string
    {
        if (blank($key)) {
            return 'Add the Claude API key first.';
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->timeout(20)
                ->get('https://api.anthropic.com/v1/models', ['limit' => 1]);
        } catch (ConnectionException) {
            return "Claude didn't answer. Try again in a moment.";
        }

        return $response->successful() ? null : 'Claude said: '.($response->json('error.message') ?? "error {$response->status()}");
    }
}
