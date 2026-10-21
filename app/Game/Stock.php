<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Region;
use Carbon\CarbonImmutable;

/**
 * What a region has left to gather. Each region holds only so much Corn or
 * material; gathering takes from it, and it grows back steadily until it
 * is full again. An empty region can't be gathered until some has grown.
 */
final class Stock
{
    /**
     * How much a region holds when full, and how fast it grows back.
     *
     * @return array{max: int, per_hour: int}
     */
    public static function rule(Region $region): array
    {
        $kind = match (true) {
            $region->produces === 'corn' => 'corn',
            in_array($region->produces, Gathering::RARE_MATERIALS, true) => 'rare',
            default => 'material',
        };

        ['max' => $max, 'per_hour' => $rate] = config("game.stock.{$kind}");
        // The further from the farms, the less there is and the slower it grows back.
        $rich = Geography::regionRichness($region->key);

        return ['max' => max(1, (int) round($max * $rich)), 'per_hour' => max(1, (int) round($rate * $rich))];
    }

    /**
     * What is there to gather now, and how far its growing back has been
     * counted (null when it is full).
     *
     * @return array{left: int, max: int, from: CarbonImmutable|null}
     */
    public static function state(Region $region, ?CarbonImmutable $now = null): array
    {
        ['max' => $max, 'per_hour' => $rate] = self::rule($region);

        if ($region->stock === null || $region->stock_at === null) {
            return ['left' => $max, 'max' => $max, 'from' => null];
        }

        $now ??= CarbonImmutable::now();
        $from = CarbonImmutable::instance($region->stock_at);
        $grown = (int) floor(max(0, $from->diffInMinutes($now)) * $rate / 60);

        if ($region->stock + $grown >= $max) {
            return ['left' => $max, 'max' => $max, 'from' => null];
        }

        // Keep the part of a unit still growing for next time.
        return ['left' => $region->stock + $grown, 'max' => $max, 'from' => $from->addMinutes((int) round($grown * 60 / $rate))];
    }

    /** When the next unit will have grown, for a region that has run out. */
    public static function nextAt(Region $region): CarbonImmutable
    {
        ['from' => $from] = self::state($region);

        return ($from ?? CarbonImmutable::now())->addMinutes((int) ceil(60 / self::rule($region)['per_hour']));
    }

    /** When a region will be full again, or null when it is. */
    public static function fullAt(Region $region): ?CarbonImmutable
    {
        ['left' => $left, 'max' => $max, 'from' => $from] = self::state($region);

        return $from === null ? null : $from->addMinutes((int) ceil(($max - $left) * 60 / self::rule($region)['per_hour']));
    }

    /**
     * Strip a field bare into an animal's bag, as a plunder or a won raid does
     * (her rule, 2026-10-21). Water still needs a barrel to carry it. Both rows
     * must be locked. Returns how much was taken.
     */
    public static function strip(Region $region, Player $player): int
    {
        if ($region->produces === null || $region->isScrub()) {
            return 0;
        }

        $taken = self::state($region)['left'];

        if ($region->produces === 'water' && config('game.survival.enabled')) {
            $taken = min($taken, max(0, Survival::waterCap($player) - $player->material('water')));
        }

        if ($taken <= 0) {
            return 0;
        }

        self::take($region, $taken);

        if ($region->produces === 'corn') {
            $player->corn += $taken;
        } else {
            $materials = $player->materials;
            $materials[$region->produces] = (int) ($materials[$region->produces] ?? 0) + $taken;
            $player->materials = $materials;
        }

        $player->save();

        return $taken;
    }

    /** Take what was gathered. The region must be locked. */
    public static function take(Region $region, int $amount): void
    {
        ['left' => $left, 'from' => $from] = self::state($region);

        $region->forceFill([
            'stock' => max(0, $left - $amount),
            'stock_at' => $from ?? CarbonImmutable::now(),
        ])->save();
    }

    /**
     * What the map shows about a region's stock.
     *
     * @return array{left: int, max: int, perHour: int, nextAt: string|null, fullAt: string|null}|null
     */
    public static function map(Region $region): ?array
    {
        if ($region->produces === null || $region->wild) {
            return null;
        }

        ['left' => $left, 'max' => $max] = self::state($region);

        return [
            'left' => $left,
            'max' => $max,
            'perHour' => self::rule($region)['per_hour'],
            'nextAt' => $left === 0 ? self::nextAt($region)->toIso8601String() : null,
            'fullAt' => self::fullAt($region)?->toIso8601String(),
        ];
    }
}
