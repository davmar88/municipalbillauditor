<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ReadingType;
use App\Enums\Service;
use App\Enums\Unit;
use Illuminate\Validation\Rule;

/**
 * Validation rules for line item input, shared by the bill requests and
 * AI extraction.
 */
final class LineItemRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'line_items.*.'): array
    {
        return [
            $prefix.'service' => ['required', Rule::enum(Service::class)],
            $prefix.'description' => ['nullable', 'string', 'max:255'],
            $prefix.'tariff_category' => ['nullable', 'string', 'max:255'],
            $prefix.'reading_type' => ['nullable', Rule::enum(ReadingType::class)],
            $prefix.'previous_reading' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            $prefix.'current_reading' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            $prefix.'consumption' => ['nullable', 'numeric', 'min:-999999999', 'max:999999999'],
            $prefix.'unit' => ['nullable', Rule::enum(Unit::class)],
            $prefix.'amount_cents' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ];
    }

    /**
     * Normalises one validated line item: defaults reading_type to unknown
     * and computes consumption from the readings when it is missing.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function normalise(array $item): array
    {
        $number = fn (mixed $v): ?float => $v === null || $v === '' ? null : (float) $v;
        $string = fn (mixed $v): ?string => $v === null || trim((string) $v) === '' ? null : trim((string) $v);

        $previous = $number($item['previous_reading'] ?? null);
        $current = $number($item['current_reading'] ?? null);
        $consumption = $number($item['consumption'] ?? null);

        if ($consumption === null && $previous !== null && $current !== null) {
            $consumption = round($current - $previous, 3);
        }

        return [
            'service' => $item['service'],
            // Never null: the contract types description as a string.
            'description' => $string($item['description'] ?? null) ?? '',
            'tariff_category' => $string($item['tariff_category'] ?? null),
            'reading_type' => $string($item['reading_type'] ?? null) ?? ReadingType::Unknown->value,
            'previous_reading' => $previous,
            'current_reading' => $current,
            'consumption' => $consumption,
            'unit' => $string($item['unit'] ?? null),
            'amount_cents' => (int) $item['amount_cents'],
        ];
    }
}
