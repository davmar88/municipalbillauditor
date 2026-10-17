<?php

namespace App\Game;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Valley time. Curfews, the secret-edit window, the quiet hours and Gate
 * Night all run on South African time, whatever the server's clock says.
 */
final class Clock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone(config('game.timezone'));
    }

    public static function local(DateTimeInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(config('game.timezone'));
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /**
     * The start of today in valley time, in the app's own timezone, so it
     * compares correctly with stored timestamps in queries.
     */
    public static function startOfToday(): CarbonImmutable
    {
        return self::now()->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** Whether the hour of the day falls in [from, from + hours), wrapping past midnight. */
    public static function hourIn(DateTimeInterface $time, int $from, int $hours): bool
    {
        $hour = self::local($time)->hour;
        $offset = ($hour - $from + 24) % 24;

        return $offset < $hours;
    }
}
