<?php

namespace App\Support;

/**
 * Formats integer cents as rand: "R1 523.40", "R0.00", "-R12.00".
 *
 * Deliberately does not use Intl/locale output so the format is identical
 * everywhere: "R" + integer part grouped in thousands with a normal space +
 * "." + two decimals.
 */
final class Money
{
    public static function format(int $cents): string
    {
        $negative = $cents < 0;
        // Work on the decimal string so PHP_INT_MIN cannot overflow abs().
        $digits = ltrim((string) $cents, '-');
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);

        $rands = substr($digits, 0, -2);
        $decimals = substr($digits, -2);

        return ($negative ? '-' : '').'R'.self::group($rands).'.'.$decimals;
    }

    /**
     * "12345678" -> "12 345 678".
     */
    public static function group(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '0';
        }

        $groups = [];
        while (strlen($digits) > 3) {
            array_unshift($groups, substr($digits, -3));
            $digits = substr($digits, 0, -3);
        }
        array_unshift($groups, $digits);

        return implode(' ', $groups);
    }
}
