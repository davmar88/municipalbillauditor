<?php

namespace App\Services\Replies;

use App\Audit\Money;
use App\Enums\ReplyClassification;
use App\Services\Claude\ClaudeUsage;

/**
 * What a municipality's reply says, checked for sense.
 */
final readonly class ReplyReading
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ReplyClassification $classification,
        public ?string $reference,
        public ?string $creditAmount,
        public ?string $reason,
        public ?string $informationRequested,
        public string $summary,
        public ?string $customerAction,
        public array $raw = [],
        public ?ClaudeUsage $usage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The decoded structured output.
     */
    public static function fromArray(array $data, ?ClaudeUsage $usage = null): self
    {
        $amount = $data['credit_amount'] ?? null;

        return new self(
            classification: ReplyClassification::tryFrom((string) ($data['classification'] ?? '')) ?? ReplyClassification::Unread,
            reference: self::text($data['reference'] ?? null, 60),
            creditAmount: is_int($amount) || is_float($amount) ? ($amount > 0 ? (string) Money::money(sprintf('%.2F', $amount)) : null) : null,
            reason: self::text($data['reason'] ?? null),
            informationRequested: self::text($data['information_requested'] ?? null),
            summary: self::text($data['summary'] ?? null, 1000) ?? 'The municipality replied.',
            customerAction: self::text($data['customer_action'] ?? null, 1000),
            raw: $data,
            usage: $usage,
        );
    }

    /** Not read automatically: our team looks at it. */
    public static function unread(string $why): self
    {
        return new self(ReplyClassification::Unread, null, null, null, null, $why, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'credit_amount' => $this->creditAmount,
            'reason' => $this->reason,
            'information_requested' => $this->informationRequested,
            'summary' => $this->summary,
            'customer_action' => $this->customerAction,
        ];
    }

    private static function text(mixed $value, int $limit = 500): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
