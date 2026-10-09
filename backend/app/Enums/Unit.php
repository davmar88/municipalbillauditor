<?php

namespace App\Enums;

enum Unit: string
{
    use EnumValues;

    case Kl = 'kl';
    case Kwh = 'kwh';

    public function label(): string
    {
        return match ($this) {
            self::Kl => 'kl',
            self::Kwh => 'kWh',
        };
    }
}
