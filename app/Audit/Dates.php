<?php

namespace App\Audit;

use DateTimeImmutable;
use DateTimeInterface;

final class Dates
{
    /** 12 Sep */
    public static function short(DateTimeInterface $day): string
    {
        return $day->format('j M');
    }

    /** 12 September */
    public static function dayMonth(DateTimeInterface $day): string
    {
        return $day->format('j F');
    }

    /** 3 October 2026 */
    public static function long(DateTimeInterface $day): string
    {
        return $day->format('j F Y');
    }

    /** Signed whole days from one date to another. */
    public static function daysBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $start = new DateTimeImmutable($from->format('Y-m-d'));
        $end = new DateTimeImmutable($to->format('Y-m-d'));

        return (int) $start->diff($end)->format('%r%a');
    }

    public static function addDays(DateTimeInterface $day, int $days): DateTimeImmutable
    {
        return (new DateTimeImmutable($day->format('Y-m-d')))->modify(sprintf('%+d days', $days));
    }

    public static function max(DateTimeInterface $a, DateTimeInterface $b): DateTimeImmutable
    {
        return new DateTimeImmutable(($a > $b ? $a : $b)->format('Y-m-d'));
    }

    public static function min(DateTimeInterface $a, DateTimeInterface $b): DateTimeImmutable
    {
        return new DateTimeImmutable(($a < $b ? $a : $b)->format('Y-m-d'));
    }
}
