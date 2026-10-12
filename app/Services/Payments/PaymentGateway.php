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
     * Ask the processor how a payment went. Used to show the customer the
     * result when they come back; crediting waits for the webhook.
     */
    public function verify(string $reference): ChargeResult;

    /**
     * Charge a saved card. The webhook confirms a successful charge.
     *
     * @throws PaymentFailed
     */
    public function chargeSavedCard(Payment $payment, string $authorizationCode, string $email): ChargeResult;

    /**
     * Whether a webhook really came from the processor.
     */
    public function validSignature(string $payload, ?string $signature): bool;
}
