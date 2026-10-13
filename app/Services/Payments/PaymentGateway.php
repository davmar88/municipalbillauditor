<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * The card processor. PaystackGateway talks to Paystack; tests use a fake.
 */
interface PaymentGateway
{
    /**
     * Start a checkout and return the page to send the customer to.
     *
     * @throws PaymentFailed
     */
    public function checkout(Payment $payment, string $email, string $callbackUrl): string;

    /**
     * Ask the processor how a payment went: when the customer comes back,
     * and for payments still open.
     *
     * @throws PaymentFailed
     */
    public function verify(string $reference): ChargeResult;

    /**
     * Whether a webhook really came from the processor.
     */
    public function validSignature(string $payload, ?string $signature): bool;
}
