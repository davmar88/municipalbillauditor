<?php

namespace App\Game;

use App\Models\Season;
use Carbon\CarbonImmutable;

/**
 * The year turns within every season: spring, summer, fall and winter, a
 * quarter each. Summer brings plenty, fall dries the water up, winter makes
 * food scarce and animals hungrier. A new season starts in spring again.
 */
final class Weather
{
    public const TIMES = [
        'spring' => ['label' => 'Spring', 'emoji' => '🌱', 'note' => 'Mild days. Everything grows as usual.'],
        'summer' => ['label' => 'Summer', 'emoji' => '☀️', 'note' => 'Food is plentiful: everything gathered comes in half as much again. The heat makes animals thirstier.'],
        'fall' => ['label' => 'Fall', 'emoji' => '🍂', 'note' => 'The water dries up: ponds and springs give little, and the Paddock trough runs dry. Drink from what you stored.'],
        'winter' => ['label' => 'Winter', 'emoji' => '❄️', 'note' => 'Food is scarce: Corn and crops come in at half, and the cold makes animals hungrier.'],
    ];

    /** Crops that grow in the fields and farms, which winter thins out. */
    private const CROPS = ['corn', 'honey', 'herbs', 'pears', 'carrots', 'berries', 'pumpkins', 'eggs'];

    /** The time of year at a moment: spring when there's no season running. */
    public static function at(?Season $season, ?CarbonImmutable $now = null): string
    {
        if ($season === null) {
            return 'spring';
        }

        $keys = array_keys(self::TIMES);

        return $keys[min(3, (int) floor(self::progress($season, $now) * 4))];
    }

    /** How far through the season, from 0 to 1. */
    public static function progress(Season $season, ?CarbonImmutable $now = null): float
    {
        $now ??= CarbonImmutable::now();
        $length = max(1, $season->starts_at->diffInSeconds($season->ends_at));

        return max(0.0, min(1.0, $season->starts_at->diffInSeconds($now, false) / $length));
    }

    /**
     * What the map shows: the time of year, how far through the season we
     * are, and when each time of year starts.
     *
     * @return array<string, mixed>
     */
    public static function forMap(?Season $season): array
    {
        $now = self::at($season);
        $quarters = [];

        foreach (array_keys(self::TIMES) as $i => $key) {
            $quarters[] = [
                'key' => $key,
                ...self::TIMES[$key],
                'startsAt' => $season?->starts_at->addSeconds((int) round($season->starts_at->diffInSeconds($season->ends_at) * $i / 4))->toIso8601String(),
            ];
        }

        return [
            'now' => $now,
            ...self::TIMES[$now],
            'progress' => $season ? round(self::progress($season), 4) : 0,
            'startsAt' => $season?->starts_at->toIso8601String(),
            'endsAt' => $season?->ends_at->toIso8601String(),
            'times' => $quarters,
        ];
    }

    /** How much more or less gathering brings in at this time of year. */
    public static function gathering(?Season $season, ?string $produces): float
    {
        if (! config('game.survival.enabled')) {
            return 1.0;
        }

        return match (self::at($season)) {
            'summer' => 1.5,
            'winter' => in_array($produces, self::CROPS, true) ? 0.5 : 1.0,
            'fall' => $produces === 'water' ? 0.4 : 1.0,
            default => 1.0,
        };
    }

    /** How much faster than usual an animal gets hungry, and thirsty. @return array{0: float, 1: float} */
    public static function needs(string $time): array
    {
        return match ($time) {
            'summer' => [1.0, 1.4],
            'winter' => [1.5, 1.0],
            default => [1.0, 1.0],
        };
    }

    /** Whether the Paddock trough and the shallow ponds are dry. */
    public static function dry(?Season $season): bool
    {
        return self::at($season) === 'fall';
    }
}
