<?php

namespace App\Enums;

enum Service: string
{
    use EnumValues;

    case Water = 'water';
    case Electricity = 'electricity';
    case Sewerage = 'sewerage';
    case Refuse = 'refuse';
    case Rates = 'rates';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Water => 'water',
            self::Electricity => 'electricity',
            self::Sewerage => 'sewerage',
            self::Refuse => 'refuse removal',
            self::Rates => 'property rates',
            self::Other => 'other charges',
        };
    }

    public function defaultUnit(): ?Unit
    {
        return match ($this) {
            self::Water => Unit::Kl,
            self::Electricity => Unit::Kwh,
            default => null,
        };
    }
}
