<?php

namespace App\Audit;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Rand amounts and quantities.
 *
 * Everything is a BigDecimal so no amount passes through a float. Amounts
 * round to the cent, half up, the way a municipal statement rounds them.
 */
final class Money
{
    public static function of(BigNumber|int|float|string $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if ($value instanceof BigNumber) {
            return $value->toBigDecimal();
        }

        if (is_string($value)) {
            $value = str_replace(['R', ',', ' '], '', trim($value));
        }

        return BigDecimal::of(is_float($value) ? (string) $value : $value);
    }

    public static function ofNullable(BigNumber|int|float|string|null $value): ?BigDecimal
    {
        return $value === null || $value === '' ? null : self::of($value);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    public static function money(BigNumber|int|float|string $value): BigDecimal
    {
        return self::of($value)->toScale(2, RoundingMode::HalfUp);
    }

    public static function round(BigNumber|int|float|string $value, int $scale): BigDecimal
    {
        return self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Divide and round once, so a shown calculation reproduces exactly.
     */
    public static function divide(BigNumber|int|string $value, BigNumber|int|string $by, int $scale = 2): BigDecimal
    {
        return self::of($value)->dividedBy($by, $scale, RoundingMode::HalfUp);
    }

    /**
     * @param  iterable<BigDecimal>  $values
     */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    /**
     * R1,234.56. Negative amounts get a leading minus: -R50.00.
     */
    public static function format(BigNumber|int|float|string $value, bool $signed = false): string
    {
        $amount = self::money($value);
        $sign = $amount->isNegative() ? '-' : ($signed && $amount->isPositive() ? '+' : '');
        [$whole, $cents] = explode('.', (string) $amount->abs());

        return $sign.'R'.self::group($whole).'.'.$cents;
    }

    /**
     * 1,140 or 15.8: thousands separators and no trailing zeros.
     */
    public static function quantity(BigNumber|int|float|string $value): string
    {
        $number = self::of($value)->strippedOfTrailingZeros();
        $text = (string) $number->abs();
        $sign = $number->isNegative() ? '-' : '';

        if (! str_contains($text, '.')) {
            return $sign.self::group($text);
        }

        [$whole, $fraction] = explode('.', $text);

        return $sign.self::group($whole).'.'.$fraction;
    }

    private static function group(string $digits): string
    {
        return ltrim(strrev(chunk_split(strrev($digits), 3, ',')), ',');
    }
}
