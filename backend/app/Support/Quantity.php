<?php

namespace App\Support;

use App\Enums\Unit;

/**
 * Human-friendly consumption figures: "38 kl", "12.2 kl", "1 250 kWh".
 */
final class Quantity
{
    public static function number(float $value, int $decimals = 1): string
    {
        $rounded = round($value, $decimals);
        $negative = $rounded < 0;
        $fixed = number_format(abs($rounded), $decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed), 2, '');
        $fraction = rtrim($fraction, '0');

        return ($negative ? '-' : '').Money::group($whole).($fraction !== '' ? '.'.$fraction : '');
    }

    public static function format(?float $value, ?Unit $unit): string
    {
        if ($value === null) {
            return 'an unknown amount';
        }

        return trim(self::number($value).' '.($unit?->label() ?? ''));
    }
}
