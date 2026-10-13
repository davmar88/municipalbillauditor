<?php

namespace App\Services\Payments;

/**
 * A card Paystack will let us charge again, without its number.
 */
final readonly class SavedCard
{
    public function __construct(
        public string $authorizationCode,
        public string $last4,
        public string $brand,
        public string $expiry,
        public string $email,
    ) {}
}
