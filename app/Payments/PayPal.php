<?php

namespace App\Payments;

use App\Models\ApplePurchase;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Payments in US dollars. The player approves the order on PayPal's page,
 * and we take the money when they come back, so an order nobody comes back
 * from never charges anyone.
 */
final class PayPal
{
    public function __construct(private readonly Settings $settings) {}

    public function ready(): bool
    {
        return $this->settings->get('paypal_client_id') !== null && $this->settings->get('paypal_client_secret') !== null;
    }

    public function live(): bool
    {
        return $this->settings->get('paypal_mode') === 'live';
    }

    /**
     * Open an order and return its id and PayPal's page for it.
     *
     * @return array{id: string, url: string}
     *
     * @throws PaymentException
     */
    public function start(ApplePurchase $purchase, string $returnUrl, string $cancelUrl): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => $purchase->reference])
            ->post($this->base().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $purchase->reference,
                    'custom_id' => $purchase->reference,
                    'invoice_id' => $purchase->reference,
                    'description' => "{$purchase->apples} Apples",
                    'amount' => ['currency_code' => $purchase->currency, 'value' => self::money($purchase->amount)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => config('app.name'),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ]]],
            ]));

        $id = $response->json('id');
        $url = collect($response->json('links', []))->firstWhere('rel', 'payer-action')['href']
            ?? collect($response->json('links', []))->firstWhere('rel', 'approve')['href']
            ?? null;

        if (! $response->successful() || ! is_string($id) || ! is_string($url)) {
            Log::warning('PayPal refused an order', ['purchase' => $purchase->id, 'status' => $response->status(), 'issue' => $response->json('details.0.issue') ?? $response->json('name')]);

            throw new PaymentException("PayPal couldn't start the payment. Try again in a moment.");
        }

        return ['id' => $id, 'url' => $url];
    }

    /**
     * Take the money for an approved order, and return the order as it then
     * stands. An order taken already comes back as it is.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function capture(string $orderId): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->withBody('{}', 'application/json')
            ->post($this->base().'/v2/checkout/orders/'.rawurlencode($orderId).'/capture'));

        if ($response->successful()) {
            return $response->json();
        }

        // Taken already, or not approved yet: say how the order stands.
        if (in_array($response->json('details.0.issue'), ['ORDER_ALREADY_CAPTURED', 'ORDER_NOT_APPROVED'], true)) {
            return $this->order($orderId);
        }

        Log::warning('PayPal capture failed', ['order' => $orderId, 'status' => $response->status(), 'issue' => $response->json('details.0.issue') ?? $response->json('name')]);

        throw new PaymentException("PayPal didn't take the payment. You weren't charged.");
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function order(string $orderId): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->get($this->base().'/v2/checkout/orders/'.rawurlencode($orderId)));

        if (! $response->successful()) {
            throw new PaymentException("PayPal didn't answer. Try again in a moment.");
        }

        return $response->json();
    }

    /** Null when the keys work, otherwise what PayPal said. */
    public function test(): ?string
    {
        if (! $this->ready()) {
            return 'Add the client ID and secret first.';
        }

        try {
            $this->token();
        } catch (PaymentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** "5.70" from 570 cents. */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws PaymentException
     */
    private function call(callable $request): Response
    {
        if (! $this->ready()) {
            throw new PaymentException('Paying with PayPal is not open yet.');
        }

        try {
            $token = $this->token();
        } catch (PaymentException) {
            // What PayPal said about the keys is for the owner's eyes, not the player's.
            throw new PaymentException("PayPal couldn't start the payment. Try again in a moment.");
        }

        try {
            return $request(Http::withToken($token)->acceptJson()->asJson()->timeout(20));
        } catch (ConnectionException) {
            throw new PaymentException("PayPal didn't answer. Try again in a moment.");
        }
    }

    /** @throws PaymentException */
    private function token(): string
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth((string) $this->settings->get('paypal_client_id'), (string) $this->settings->get('paypal_client_secret'))
                ->acceptJson()
                ->timeout(20)
                ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new PaymentException("PayPal didn't answer. Try again in a moment.");
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            Log::warning('PayPal refused our keys', ['status' => $response->status(), 'error' => $response->json('error')]);

            throw new PaymentException('PayPal said: '.($response->json('error_description') ?? "error {$response->status()}"));
        }

        return $token;
    }

    private function base(): string
    {
        return $this->live() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }
}
