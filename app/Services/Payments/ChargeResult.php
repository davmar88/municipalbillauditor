<?php

namespace App\Services\Payments;

/**
 * What the processor says about one charge, in the shape of Paystack's
 * transaction object.
 */
final readonly class ChargeResult
{
    public function __construct(
        public string $reference,
        /** success, failed, abandoned, pending... as the processor reports it. */
        public string $status,
        public int $amountInCents,
        public string $currency,
        public ?string $message = null,
        public ?SavedCard $card = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Paystack's "data" object.
     */
    public static function fromPaystack(array $data): self
    {
        $authorization = is_array($data['authorization'] ?? null) ? $data['authorization'] : [];
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];

        return new self(
            reference: (string) ($data['reference'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            amountInCents: (int) ($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            message: isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
            card: ($authorization['reusable'] ?? false) === true && filled($authorization['authorization_code'] ?? null)
                ? new SavedCard(
                    authorizationCode: (string) $authorization['authorization_code'],
                    last4: (string) ($authorization['last4'] ?? ''),
                    brand: (string) ($authorization['card_type'] ?? $authorization['brand'] ?? ''),
                    expiry: sprintf('%s/%s', $authorization['exp_month'] ?? '', $authorization['exp_year'] ?? ''),
                    email: (string) ($customer['email'] ?? ''),
                )
                : null,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }
}
