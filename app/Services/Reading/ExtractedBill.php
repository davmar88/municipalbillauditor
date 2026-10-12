<?php

namespace App\Services\Reading;

use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A bill as read from a photo or PDF, checked for sense.
 *
 * Structured outputs guarantee the shape of what comes back; this class
 * guards the values. Anything doubtful becomes a warning, which puts the bill
 * up for review rather than silently trusting it.
 */
final readonly class ExtractedBill
{
    /**
     * @param  list<array{service: Service, meter_number: ?string, previous: ?string, current: ?string, consumption: ?string, unit: ?string, reading_type: ReadingType}>  $readings
     * @param  list<array{service: Service, description: string, amount: string, quantity: ?string, unit: ?string}>  $charges
     * @param  list<string>  $notes
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $isMunicipalBill,
        public ?string $municipality,
        public ?string $municipalityEmail,
        public ?string $municipalityAddress,
        public ?string $municipalityPhone,
        public ?string $accountNumber,
        public ?CarbonImmutable $billDate,
        public ?CarbonImmutable $dueDate,
        public ?CarbonImmutable $periodStart,
        public ?CarbonImmutable $periodEnd,
        public ?string $propertyAddress,
        public ?string $suburb,
        public ?string $tariffLabel,
        public ?string $totalDue,
        public array $readings,
        public array $charges,
        public array $notes,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The decoded structured output.
     */
    public static function fromArray(array $data): self
    {
        $notes = [];
        $unreadable = self::text($data['unreadable_notes'] ?? null);

        if ($unreadable !== null) {
            $notes[] = "Not clearly readable: {$unreadable}";
        }

        $readings = [];

        foreach ((array) ($data['meter_readings'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $readings[] = [
                'service' => Service::tryFrom((string) ($row['service'] ?? '')) ?? Service::Other,
                'meter_number' => self::text($row['meter_number'] ?? null),
                'previous' => self::number($row['previous'] ?? null, 3),
                'current' => self::number($row['current'] ?? null, 3),
                'consumption' => self::number($row['consumption'] ?? null, 3),
                'unit' => self::text($row['unit'] ?? null),
                'reading_type' => ReadingType::tryFrom((string) ($row['reading_type'] ?? '')) ?? ReadingType::Unknown,
            ];
        }

        $charges = [];

        foreach ((array) ($data['charges'] ?? []) as $row) {
            $amount = is_array($row) ? self::number($row['amount'] ?? null, 2) : null;

            if ($amount === null) {
                continue;
            }

            $charges[] = [
                'service' => Service::tryFrom((string) ($row['service'] ?? '')) ?? Service::Other,
                'description' => self::text($row['description'] ?? null) ?? 'Charge',
                'amount' => $amount,
                'quantity' => self::number($row['quantity'] ?? null, 3),
                'unit' => self::text($row['unit'] ?? null),
            ];
        }

        return new self(
            isMunicipalBill: (bool) ($data['is_municipal_bill'] ?? false),
            municipality: self::text($data['municipality'] ?? null),
            municipalityEmail: self::email($data['municipality_email'] ?? null),
            municipalityAddress: self::text($data['municipality_postal_address'] ?? null),
            municipalityPhone: self::text($data['municipality_phone'] ?? null),
            accountNumber: self::text($data['account_number'] ?? null),
            billDate: self::date($data['bill_date'] ?? null),
            dueDate: self::date($data['due_date'] ?? null),
            periodStart: self::date($data['period_start'] ?? null),
            periodEnd: self::date($data['period_end'] ?? null),
            propertyAddress: self::text($data['property_address'] ?? null),
            suburb: self::text($data['suburb'] ?? null),
            tariffLabel: self::text($data['tariff'] ?? null),
            totalDue: self::number($data['total_due'] ?? null, 2),
            readings: $readings,
            charges: $charges,
            notes: $notes,
            raw: $data,
        );
    }

    /**
     * Reasons to have a person look at this bill before trusting it.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = $this->notes;

        if ($this->billDate === null) {
            $warnings[] = "We couldn't read the bill date.";
        }

        if ($this->periodStart !== null && $this->periodEnd !== null && $this->periodEnd->lessThan($this->periodStart)) {
            $warnings[] = 'The billing period ends before it starts.';
        }

        foreach ($this->readings as $reading) {
            if ($reading['previous'] === null || $reading['current'] === null || $reading['consumption'] === null) {
                continue;
            }

            $difference = BigDecimal::of($reading['current'])->minus($reading['previous']);

            if ($difference->isPositiveOrZero() && $difference->minus($reading['consumption'])->abs()->isGreaterThan('0.5')) {
                $warnings[] = 'A '.$reading['service']->value.' reading says '.Money::quantity($reading['consumption'])
                    .' used, but the meter moved by '.Money::quantity($difference).'.';
            }
        }

        if ($this->totalDue !== null && $this->charges !== []) {
            $sum = Money::sum(array_map(fn ($c) => BigDecimal::of($c['amount']), $this->charges));
            $gap = $sum->minus($this->totalDue)->abs();

            if ($gap->isGreaterThan('1')) {
                $warnings[] = 'The charges add up to '.Money::format($sum).', but the total due is '
                    .Money::format($this->totalDue).'. The difference may be an older balance or a payment.';
            }
        }

        return $warnings;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private static function email(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value, " \t<>.;,"));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /**
     * JSON numbers arrive as floats: round through a fixed-point string so
     * 562.05 stays 562.05 rather than 562.0499999...
     */
    private static function number(mixed $value, int $scale): ?string
    {
        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value)) {
                return null;
            }

            return (string) BigDecimal::of(sprintf('%.6F', $value))->toScale($scale, RoundingMode::HalfUp);
        }

        // A separator in a string is ambiguous (1 596,00 or 1,596.00), so only
        // plain numbers are accepted.
        if (is_string($value) && is_numeric(trim($value))) {
            return (string) BigDecimal::of(trim($value))->toScale($scale, RoundingMode::HalfUp);
        }

        return null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        // Reject dates PHP rolled over, such as 2026-02-31.
        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }
}
