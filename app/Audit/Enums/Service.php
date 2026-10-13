<?php

namespace App\Audit\Enums;

use Brick\Math\BigDecimal;

enum Service: string
{
    case Water = 'water';
    case Electricity = 'electricity';
    case Sanitation = 'sanitation';
    case Refuse = 'refuse';
    case Rates = 'rates';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Water => 'Water',
            self::Electricity => 'Electricity',
            self::Sanitation => 'Sanitation',
            self::Refuse => 'Refuse removal',
            self::Rates => 'Property rates',
            self::Other => 'Other',
        };
    }

    public function isMetered(): bool
    {
        return $this === self::Water || $this === self::Electricity;
    }

    public function defaultUnit(): string
    {
        return match ($this) {
            self::Water => 'kL',
            self::Electricity => 'kWh',
            default => '',
        };
    }

    /** Decimal places a quantity is shown with, and so rounded to. */
    public function quantityScale(): int
    {
        return $this === self::Electricity ? 0 : 1;
    }

    /** The smallest excess worth raising, so tiny differences are not flagged. */
    public function minimumExcess(): BigDecimal
    {
        return BigDecimal::of($this === self::Electricity ? 50 : 2);
    }

    /** @return list<self> */
    public static function metered(): array
    {
        return [self::Water, self::Electricity];
    }
}
