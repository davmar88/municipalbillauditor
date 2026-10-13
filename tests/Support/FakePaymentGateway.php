<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\PaymentGateway;

/**
 * Stands in for Paystack: records what was asked of it and answers as told.
 * Webhook signatures use the real HMAC with a test secret.
 */
class FakePaymentGateway implements PaymentGateway
{
    public const SECRET = 'sk_test_fake';

    /** @var list<array{payment: Payment, email: string, callback: string}> */
    public array $checkouts = [];

    /** @var list<array{payment: Payment, code: string, email: string}> */
    public array $savedCardCharges = [];

    /** What a saved-card charge reports: success, failed... */
    public string $savedCardStatus = 'success';

    /** @var list<string> References asked about. */
    public array $verified = [];

    /** What asking about a payment reports: success, abandoned... */
    public string $verifyStatus = 'success';

    /** The amount it reports, when not the payment's own. */
    public ?int $verifyAmountInCents = null;

    public function checkout(Payment $payment, string $email, string $callbackUrl): string
    {
        $this->checkouts[] = ['payment' => $payment, 'email' => $email, 'callback' => $callbackUrl];

        return 'https://checkout.paystack.com/'.$payment->reference;
    }

    public function verify(string $reference): ChargeResult
    {
        $this->verified[] = $reference;
        $payment = Payment::query()->where('reference', $reference)->first();

        return new ChargeResult($reference, $this->verifyStatus, $this->verifyAmountInCents ?? $payment?->amountInCents() ?? 0, 'ZAR');
    }

    public function chargeSavedCard(Payment $payment, string $authorizationCode, string $email): ChargeResult
    {
        $this->savedCardCharges[] = ['payment' => $payment, 'code' => $authorizationCode, 'email' => $email];

        return new ChargeResult($payment->reference, $this->savedCardStatus, $payment->amountInCents(), 'ZAR', $this->savedCardStatus === 'success' ? 'Approved' : 'Declined');
    }

    public function validSignature(string $payload, ?string $signature): bool
    {
        return hash_equals(self::sign($payload), (string) $signature);
    }

    public static function sign(string $payload): string
    {
        return hash_hmac('sha512', $payload, self::SECRET);
    }
}
