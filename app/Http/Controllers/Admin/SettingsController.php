<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplePurchase;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Warrant;
use App\Payments\PayPal;
use App\Payments\Paystack;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's page: the Paystack, PayPal and Claude keys, whether Apple
 * packs are on sale, and what the sales brought in. Saved keys are never
 * sent back to the browser, only their last four characters.
 */
class SettingsController extends Controller
{
    /** Keys the owner can remove. */
    public const REMOVABLE = ['paystack_secret_key', 'paypal_client_id', 'paypal_client_secret', 'anthropic_api_key'];

    public function index(Settings $settings, Paystack $paystack, PayPal $paypal): Response
    {
        $paid = ApplePurchase::query()->where('status', 'paid');
        $sold = (int) (clone $paid)->sum('apples');
        $held = (int) Player::query()->sum('apples') + (int) Faction::query()->sum('apples') + (int) Warrant::query()->where('status', 'open')->sum('bounty');

        return Inertia::render('admin/settings', [
            'settings' => [
                'applesOnSale' => $settings->on('apples_on_sale'),
                'paystackSecretKey' => $settings->hint('paystack_secret_key'),
                'paystackLive' => str_starts_with((string) $settings->get('paystack_secret_key'), 'sk_live_'),
                'paypalClientId' => $settings->get('paypal_client_id'),
                'paypalClientSecret' => $settings->hint('paypal_client_secret'),
                'paypalMode' => $settings->get('paypal_mode', 'sandbox'),
                'anthropicApiKey' => $settings->hint('anthropic_api_key'),
                'anthropicFromServer' => $settings->get('anthropic_api_key') === null && filled(config('game.translator.api_key')),
            ],
            'ready' => ['paystack' => $paystack->ready(), 'paypal' => $paypal->ready()],
            'webhookUrl' => route('webhooks.paystack'),
            'sales' => [
                'count' => (clone $paid)->count(),
                'zar' => (int) (clone $paid)->where('currency', 'ZAR')->sum('amount'),
                'usd' => (int) (clone $paid)->where('currency', 'USD')->sum('amount'),
                'sold' => $sold,
                'held' => $held,
                'kept' => max(0, $sold - $held),
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
                    'apples' => $purchase->apples,
                    'price' => $purchase->price(),
                    'via' => $purchase->provider,
                    'reference' => $purchase->reference,
                    'status' => $purchase->status,
                    'at' => $purchase->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function update(Request $request, Settings $settings, Paystack $paystack, PayPal $paypal): RedirectResponse
    {
        $data = $request->validate([
            'apples_on_sale' => ['required', 'boolean'],
            'paystack_secret_key' => ['nullable', 'string', 'max:200', 'starts_with:sk_test_,sk_live_'],
            'paypal_client_id' => ['nullable', 'string', 'max:200'],
            'paypal_client_secret' => ['nullable', 'string', 'max:200'],
            'paypal_mode' => ['required', Rule::in(['sandbox', 'live'])],
            'anthropic_api_key' => ['nullable', 'string', 'max:300', 'starts_with:sk-ant-'],
        ], [
            'paystack_secret_key.starts_with' => 'A Paystack secret key starts with sk_test_ or sk_live_.',
            'anthropic_api_key.starts_with' => 'A Claude API key starts with sk-ant-.',
        ]);

        $user = $request->user();

        // A secret left empty keeps the one saved.
        foreach (['paystack_secret_key', 'paypal_client_secret', 'anthropic_api_key'] as $key) {
            if (filled($data[$key] ?? null)) {
                $settings->put($key, $data[$key], $user);
            }
        }

        $settings->put('paypal_client_id', $data['paypal_client_id'] ?? null, $user);
        $settings->put('paypal_mode', $data['paypal_mode'], $user);

        if ($request->boolean('apples_on_sale') && ! $paystack->ready() && ! $paypal->ready()) {
            throw ValidationException::withMessages(['apples_on_sale' => 'Add the Paystack or PayPal keys before opening sales. Your keys were saved.']);
        }

        $settings->put('apples_on_sale', $request->boolean('apples_on_sale') ? '1' : null, $user);

        return $this->told('Saved.');
    }

    public function forget(Request $request, string $key, Settings $settings): RedirectResponse
    {
        abort_unless(in_array($key, self::REMOVABLE, true), 404);
        $settings->put($key, null, $request->user());

        return $this->told('Removed.');
    }

    public function test(string $service, Settings $settings, Paystack $paystack, PayPal $paypal): RedirectResponse
    {
        $problem = match ($service) {
            'paystack' => $paystack->test(),
            'paypal' => $paypal->test(),
            'claude' => $this->testClaude($settings->get('anthropic_api_key', config('game.translator.api_key'))),
            default => abort(404),
        };

        $name = ['paystack' => 'Paystack', 'paypal' => 'PayPal', 'claude' => 'Claude'][$service];

        return $problem === null
            ? $this->told("{$name} accepted the key.")
            : $this->told($problem, 'error');
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
