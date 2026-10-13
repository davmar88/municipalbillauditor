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
    ) {}

    /**
     * @param  array<string, mixed>  $data  Paystack's "data" object.
     */
    public static function fromPaystack(array $data): self
    {
        return new self(
            reference: (string) ($data['reference'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            amountInCents: (int) ($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            message: isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === 'success';
    }
}
