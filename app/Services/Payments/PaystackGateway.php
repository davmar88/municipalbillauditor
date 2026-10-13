<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack's transaction API: hosted checkout, verification, charging a
 * saved card, and webhook signatures (an HMAC-SHA512 of the raw body with
 * the secret key).
 *
 * Every payment stays in the account's main balance: no subaccount or split
 * is ever sent, and nothing here can pay money out.
 */
class PaystackGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.paystack.co',
        private readonly int $timeout = 20,
        /** This site's name on each payment in Paystack's dashboard. */
        private readonly string $site = '',
    ) {}

    public function checkout(Payment $payment, string $email, string $callbackUrl): string
    {
        $response = $this->send('post', '/transaction/initialize', [
            'email' => $email,
            'amount' => $payment->amountInCents(),
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $callbackUrl,
            'metadata' => $this->metadata($payment),
        ]);

        $url = $response->json('data.authorization_url');

        if (! is_string($url) || $url === '') {
            throw new PaymentFailed("We couldn't start the card payment. Please try again.");
        }

        return $url;
    }

    public function verify(string $reference): ChargeResult
    {
        $response = $this->send('get', '/transaction/verify/'.rawurlencode($reference));

        return ChargeResult::fromPaystack((array) $response->json('data'));
    }

    public function validSignature(string $payload, ?string $signature): bool
    {
        if ($this->secretKey === '' || blank($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $payload, $this->secretKey), (string) $signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Payment $payment): array
    {
        return [
            'organisation_id' => $payment->organisation_id,
            'payment_id' => $payment->id,
            // Paystack's dashboard shows custom fields on the payment, which
            // tells this site's payments apart in an account shared with another.
            ...($this->site !== '' ? ['custom_fields' => [['display_name' => 'Site', 'variable_name' => 'site', 'value' => $this->site]]] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws PaymentFailed
     */
    private function send(string $method, string $path, array $body = []): Response
    {
        if ($this->secretKey === '') {
            throw new PaymentFailed('Card payments are not set up yet.');
        }

        try {
            $response = $this->client()->{$method}($path, $body);
        } catch (ConnectionException $e) {
            Log::warning('Paystack could not be reached', ['path' => $path, 'error' => $e->getMessage()]);

            throw new PaymentFailed("We couldn't reach the card processor. Please try again.", previous: $e);
        }

        if ($response->failed() || $response->json('status') !== true) {
            Log::warning('Paystack refused a request', ['path' => $path, 'status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentFailed((string) ($response->json('message') ?: 'The card processor refused the payment.'));
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }
}
