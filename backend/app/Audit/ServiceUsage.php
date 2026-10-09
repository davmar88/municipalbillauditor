<?php

namespace App\Audit;

use App\Enums\ReadingType;
use App\Enums\Service;
use App\Enums\Unit;
use App\Models\LineItem;
use Illuminate\Support\Collection;

/**
 * One bill's charges and usage for one service, aggregated over its line
 * items. "Usage" is the sum of the line items' consumption.
 */
final class ServiceUsage
{
    /**
     * @param  Collection<int, LineItem>  $lines
     */
    private function __construct(
        public readonly Service $service,
        public readonly ?int $billId,
        public readonly Collection $lines,
        public readonly ?float $consumption,
        public readonly int $consumptionAmountCents,
        public readonly int $amountCents,
        public readonly ReadingType $readingType,
        public readonly ?Unit $unit,
        public readonly ?int $primaryLineItemId,
    ) {}

    /**
     * @param  Collection<int, LineItem>  $allLines  the bill's line items
     */
    public static function fromLines(Service $service, Collection $allLines, ?int $billId = null): self
    {
        $lines = $allLines->filter(fn (LineItem $line) => $line->service === $service)->values();

        // Lines with a usable (known, non-negative) consumption figure.
        $measured = $lines->filter(fn (LineItem $line) => $line->consumption !== null && $line->consumption >= 0);

        $consumption = $measured->isEmpty() ? null : (float) $measured->sum(fn (LineItem $l) => (float) $l->consumption);

        $typed = $measured->isNotEmpty() ? $measured : $lines;
        $readingType = match (true) {
            $typed->contains(fn (LineItem $l) => $l->reading_type === ReadingType::Estimated) => ReadingType::Estimated,
            $typed->isNotEmpty() && $typed->every(fn (LineItem $l) => $l->reading_type === ReadingType::Actual) => ReadingType::Actual,
            default => ReadingType::Unknown,
        };

        $primary = $measured->first() ?? $lines->first();

        return new self(
            service: $service,
            billId: $billId,
            lines: $lines,
            consumption: $consumption,
            consumptionAmountCents: (int) $measured->sum('amount_cents'),
            amountCents: (int) $lines->sum('amount_cents'),
            readingType: $readingType,
            unit: $measured->first(fn (LineItem $l) => $l->unit !== null)?->unit ?? $service->defaultUnit(),
            primaryLineItemId: $primary?->id,
        );
    }

    public function hasLines(): bool
    {
        return $this->lines->isNotEmpty();
    }

    public function hasUsage(): bool
    {
        return $this->consumption !== null;
    }

    /**
     * Rand value per unit of usage, from the consumption-based lines.
     */
    public function centsPerUnit(): ?float
    {
        if ($this->consumption === null || $this->consumption <= 0) {
            return null;
        }

        return $this->consumptionAmountCents / $this->consumption;
    }
}
