<?php

namespace App\Audit\Rules;

use App\Enums\Service;
use App\Enums\Unit;
use App\Models\Bill;
use App\Models\LineItem;
use App\Support\Money;
use App\Support\Quantity;

/**
 * Shared helpers for rules: config, wording and number formatting.
 */
abstract class BaseRule implements AuditRule
{
    /**
     * @return list<Service>
     */
    protected function meteredServices(): array
    {
        return array_map(
            fn (string $s) => Service::from($s),
            config('audit.metered_services', ['water', 'electricity']),
        );
    }

    protected function rand(int $cents): string
    {
        return Money::format($cents);
    }

    protected function qty(?float $value, ?Unit $unit): string
    {
        return Quantity::format($value, $unit);
    }

    protected function times(float $ratio): string
    {
        return Quantity::number($ratio);
    }

    protected function percent(float $fraction): string
    {
        return Quantity::number($fraction * 100, 0).'%';
    }

    protected function round3(?float $value): ?float
    {
        return $value === null ? null : round($value, 3);
    }

    /**
     * Sum of the bill's charges for a service.
     */
    protected function serviceAmountCents(Bill $bill, ?Service $service): int
    {
        if ($service === null) {
            return 0;
        }

        return (int) $bill->lineItems
            ->filter(fn (LineItem $l) => $l->service === $service)
            ->sum('amount_cents');
    }

    protected function evidenceUnit(?Service $service): ?Unit
    {
        return $service?->defaultUnit();
    }

    /**
     * Joins sentences, skipping empty ones.
     *
     * @param  list<string|null>  $sentences
     */
    protected function sentences(array $sentences): string
    {
        return implode(' ', array_values(array_filter($sentences, fn ($s) => $s !== null && $s !== '')));
    }
}
