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
 */
class PaystackGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.paystack.co',
        private readonly int $timeout = 20,
    ) {}

    public function checkout(Payment $payment, string $email, string $callbackUrl): string
    {
        $response = $this->send('post', '/transaction/initialize', [
            'email' => $email,
            'amount' => $payment->amountInCents(),
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'organisation_id' => $payment->organisation_id,
                'payment_id' => $payment->id,
            ],
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

    public function chargeSavedCard(Payment $payment, string $authorizationCode, string $email): ChargeResult
    {
        $response = $this->send('post', '/transaction/charge_authorization', [
            'authorization_code' => $authorizationCode,
            'email' => $email,
            'amount' => $payment->amountInCents(),
            'currency' => $payment->currency,
            'reference' => $payment->reference,
        ]);

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
