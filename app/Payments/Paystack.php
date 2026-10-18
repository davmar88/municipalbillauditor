<?php

namespace App\Payments;

use App\Models\ApplePurchase;
use App\Models\Cashout;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Card and EFT payments in rand. The player pays on Paystack's own page and
 * comes back; Paystack also reports each payment to our webhook, signed with
 * the secret key, in case they never do. Cash-outs go the other way, as
 * transfers into South African bank accounts that Paystack has checked
 * belong to the animal's own name and ID number.
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
            throw new PaymentException("Paystack didn't answer. Your Pineapples arrive as soon as it confirms the payment.");
        }

        if ($response->status() === 404 || $response->status() === 400) {
            return null;
        }

        $data = $response->json('data');

        if (! $response->successful() || ! is_array($data)) {
            Log::warning('Paystack verify failed', ['reference' => $reference, 'status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentException("Paystack didn't answer. Your Pineapples arrive as soon as it confirms the payment.");
        }

        return $data;
    }

    /**
     * South African banks Paystack can check accounts at and pay into.
     *
     * @return list<array{code: string, name: string}>
     */
    public function banks(): array
    {
        if (! $this->ready()) {
            return [];
        }

        $banks = Cache::get('paystack:banks');

        if (is_array($banks) && $banks !== []) {
            return $banks;
        }

        try {
            $response = $this->http()->get(self::API.'/bank', ['country' => 'south africa', 'currency' => 'ZAR', 'enabled_for_verification' => 'true', 'perPage' => 100]);
        } catch (ConnectionException) {
            return [];
        }

        $banks = collect($response->successful() ? $response->json('data', []) : [])
            ->filter(fn ($bank) => is_array($bank) && filled($bank['code'] ?? null) && filled($bank['name'] ?? null))
            ->map(fn (array $bank) => ['code' => (string) $bank['code'], 'name' => (string) $bank['name']])
            ->sortBy('name')
            ->values()
            ->all();

        // An empty list is a hiccup, not the answer: ask again next time.
        if ($banks !== []) {
            Cache::put('paystack:banks', $banks, now()->addDay());
        }

        return $banks;
    }

    /**
     * Ask the bank whether an account belongs to this name and ID or
     * passport number. Paystack charges R3 for each check.
     *
     * @throws PaymentException
     */
    public function validateAccount(string $name, string $accountNumber, string $bankCode, string $documentType, string $documentNumber): bool
    {
        $response = $this->payout(fn (PendingRequest $http) => $http->post(self::API.'/bank/validate', [
            'bank_code' => $bankCode,
            'country_code' => 'ZA',
            'account_number' => $accountNumber,
            'account_name' => $name,
            'account_type' => 'personal',
            'document_type' => $documentType === 'passport' ? 'passportNumber' : 'identityNumber',
            'document_number' => $documentNumber,
        ]));

        return ($response['data']['verified'] ?? false) === true && ($response['data']['accountHolderMatch'] ?? true) !== false;
    }

    /**
     * Register a checked bank account to pay into, and return Paystack's code
     * for it.
     *
     * @throws PaymentException
     */
    public function recipient(string $name, string $accountNumber, string $bankCode): string
    {
        $response = $this->payout(fn (PendingRequest $http) => $http->post(self::API.'/transferrecipient', [
            'type' => 'basa',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'ZAR',
        ]));

        $code = $response['data']['recipient_code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new PaymentRefused('Paystack accepted the account but gave no code to pay it with. Try again.');
        }

        return $code;
    }

    /**
     * Send a cash-out to the bank account. Paystack keeps our reference
     * unique, so sending the same cash-out again can't pay it twice.
     *
     * @return array{status: string, code: string|null} Paystack's status for it (success, pending, otp, failed...) and its code for the transfer
     *
     * @throws PaymentException
     */
    public function transfer(Cashout $cashout): array
    {
        $response = $this->payout(fn (PendingRequest $http) => $http->post(self::API.'/transfer', [
            'source' => 'balance',
            'amount' => $cashout->amount,
            'recipient' => $cashout->destination['recipient'] ?? '',
            'reason' => config('app.name')." cash-out of {$cashout->apples} Apples",
            'reference' => $cashout->reference,
            'currency' => 'ZAR',
        ]));

        return self::transferState($response['data'] ?? null);
    }

    /**
     * Send a transfer that waits for the OTP Paystack texted the owner.
     *
     * @return array{status: string, code: string|null}
     *
     * @throws PaymentException
     */
    public function finalize(string $transferCode, string $otp): array
    {
        $response = $this->payout(fn (PendingRequest $http) => $http->post(self::API.'/transfer/finalize_transfer', [
            'transfer_code' => $transferCode,
            'otp' => $otp,
        ]));

        $state = self::transferState($response['data'] ?? null);

        return ['status' => $state['status'], 'code' => $state['code'] ?? $transferCode];
    }

    /**
     * Text the owner a new OTP for a transfer waiting for one.
     *
     * @throws PaymentException
     */
    public function resendOtp(string $transferCode): void
    {
        $this->payout(fn (PendingRequest $http) => $http->post(self::API.'/transfer/resend_otp', [
            'transfer_code' => $transferCode,
            'reason' => 'resend_otp',
        ]));
    }

    /**
     * Paystack's status for a cash-out, or null when it has no record of it.
     *
     * @return array{status: string, code: string|null, reason: string|null}|null
     *
     * @throws PaymentException
     */
    public function transferStatus(string $reference): ?array
    {
        try {
            $response = $this->http()->get(self::API.'/transfer/verify/'.rawurlencode($reference));
        } catch (ConnectionException) {
            throw new PaymentException("Paystack didn't answer. Try again in a moment.");
        }

        if ($response->status() === 404 || ($response->status() === 400 && $response->json('data') === null)) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new PaymentException('Paystack said: '.($response->json('message') ?? "error {$response->status()}"));
        }

        return [
            ...self::transferState($response->json('data')),
            'reason' => $response->json('data.gateway_response') ?? $response->json('data.reason'),
        ];
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

    /**
     * A request about a payout, where the owner needs to know exactly what
     * Paystack said.
     *
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    private function payout(callable $call): array
    {
        if (! $this->ready()) {
            throw new PaymentRefused('Add the Paystack secret key first.');
        }

        try {
            $response = $call($this->http());
        } catch (ConnectionException) {
            throw new PaymentException("Paystack didn't answer. Try again in a moment.");
        }

        if ($response->serverError()) {
            throw new PaymentException("Paystack didn't answer. Try again in a moment.");
        }

        if (! $response->successful() || $response->json('status') !== true) {
            Log::warning('Paystack refused a payout request', ['status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentRefused('Paystack said: '.($response->json('message') ?? "error {$response->status()}"));
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

    /** @return array{status: string, code: string|null} */
    private static function transferState(mixed $data): array
    {
        $data = is_array($data) ? $data : [];

        return [
            'status' => is_string($data['status'] ?? null) ? $data['status'] : 'pending',
            'code' => is_string($data['transfer_code'] ?? null) ? $data['transfer_code'] : null,
        ];
    }
}
