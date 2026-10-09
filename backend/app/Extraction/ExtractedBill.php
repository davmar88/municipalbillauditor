<?php

namespace App\Extraction;

/**
 * Structured fields read from a bill. Dates are YYYY-MM-DD, money in cents.
 * Line items use the same fields as the API's line item input.
 */
final class ExtractedBill
{
    /**
     * @param  list<array<string, mixed>>  $lineItems
     */
    public function __construct(
        public readonly ?string $billDate,
        public readonly ?string $periodStart,
        public readonly ?string $periodEnd,
        public readonly ?string $dueDate,
        public readonly ?int $totalCents,
        public readonly array $lineItems,
    ) {}

    /**
     * Builds from the decoded JSON output (snake_case keys), dropping
     * values that aren't in the expected format.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $date = function (mixed $value): ?string {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }
            [$y, $m, $d] = array_map('intval', explode('-', $value));

            return checkdate($m, $d, $y) ? $value : null;
        };

        $lineItems = [];
        foreach ((array) ($data['line_items'] ?? []) as $item) {
            if (is_array($item)) {
                $lineItems[] = $item;
            }
        }

        return new self(
            billDate: $date($data['bill_date'] ?? null),
            periodStart: $date($data['period_start'] ?? null),
            periodEnd: $date($data['period_end'] ?? null),
            dueDate: $date($data['due_date'] ?? null),
            totalCents: is_int($data['total_cents'] ?? null) ? $data['total_cents'] : null,
            lineItems: $lineItems,
        );
    }
}
