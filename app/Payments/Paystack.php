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
 * Card and EFT payments in rand. The player pays on Paystack's own page and
 * comes back; Paystack also reports each payment to our webhook, signed with
 * the secret key, in case they never do.
 */
final class Paystack
{
    private const API = 'https://api.paystack.co';

    public function __construct(private readonly Settings $settings) {}

    public function ready(): bool
    {
        return $this->settings->get('paystack_secret_key') !== null;
    }

    /**
     * Open a payment and return Paystack's page for it.
     *
     * @throws PaymentException
     */
    public function start(ApplePurchase $purchase, string $email, string $callbackUrl): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post(self::API.'/transaction/initialize', [
            'email' => $email,
            'amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'reference' => $purchase->reference,
            'callback_url' => $callbackUrl,
            'metadata' => ['purchase_id' => $purchase->id, 'player_id' => $purchase->player_id, 'pack' => $purchase->pack],
        ]));

        $url = $response['data']['authorization_url'] ?? null;

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            Log::warning('Paystack gave no payment page', ['purchase' => $purchase->id, 'message' => $response['message'] ?? null]);

            throw new PaymentException("Paystack couldn't start the payment. Try again in a moment.");
        }

        return $url;
    }

    /**
     * What Paystack knows of a payment, or null when it knows nothing yet.
     *
     * @return array<string, mixed>|null
     *
     * @throws PaymentException
     */
    public function transaction(string $reference): ?array
    {
        try {
            $response = $this->http()->get(self::API.'/transaction/verify/'.rawurlencode($reference));
        } catch (ConnectionException) {
            throw new PaymentException("Paystack didn't answer. Your Apples arrive as soon as it confirms the payment.");
        }

        if ($response->status() === 404 || $response->status() === 400) {
            return null;
        }

        $data = $response->json('data');

        if (! $response->successful() || ! is_array($data)) {
            Log::warning('Paystack verify failed', ['reference' => $reference, 'status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentException("Paystack didn't answer. Your Apples arrive as soon as it confirms the payment.");
        }

        return $data;
    }

    /** Whether a webhook really came from Paystack: it signs the body with our secret key. */
    public function signed(string $payload, ?string $signature): bool
    {
        $secret = $this->settings->get('paystack_secret_key');

        if ($secret === null || blank($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
    }

    /** Null when the key works, otherwise what Paystack said. */
    public function test(): ?string
    {
        if (! $this->ready()) {
            return 'Add the secret key first.';
        }

        try {
            $response = $this->http()->get(self::API.'/balance');
        } catch (ConnectionException) {
            return "Paystack didn't answer. Try again in a moment.";
        }

        return $response->successful() && $response->json('status') === true
            ? null
            : 'Paystack said: '.($response->json('message') ?? "error {$response->status()}");
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    private function send(callable $call): array
    {
        if (! $this->ready()) {
            throw new PaymentException('Paying with Paystack is not open yet.');
        }

        try {
            $response = $call($this->http());
        } catch (ConnectionException) {
            throw new PaymentException("Paystack didn't answer. Try again in a moment.");
        }

        if (! $response->successful() || $response->json('status') !== true) {
            Log::warning('Paystack refused a request', ['status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentException("Paystack couldn't start the payment. Try again in a moment.");
        }

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) $this->settings->get('paystack_secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }
}
