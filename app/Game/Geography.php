<?php

namespace App\Game;

/**
 * Where things are on the map. Every region's outline and hub come from
 * resources/data/hollowmere.json, written from the map's own drawing by
 * `npm run map:geometry`, so the server and the screen agree on which
 * region a point is in.
 */
final class Geography
{
    /** How far animals can go, as on the map: x, y, width, height. Matches REACH in hollowmere.ts. */
    public const REACH = [-2650, -2930, 6250, 6920];

    /** Hollowmere's own fields, the heart of the valley: x, y, width, height. Matches FARM in hollowmere.ts. */
    private const HEART = [96, 92, 1408, 904];

    /** Resources thin out from this far from the farms, down to the least at FAR. */
    private const NEAR = 300;

    private const FAR = 1800;

    public const LEAST = 0.25;

    /** @var array<string, array{polygon: list<array{0: float, 1: float}>, hub: array{0: float, 1: float}}>|null */
    private static ?array $regions = null;

    /** @return array<string, array{polygon: list<array{0: float, 1: float}>, hub: array{0: float, 1: float}}> */
    public static function regions(): array
    {
        return self::$regions ??= json_decode((string) file_get_contents(resource_path('data/hollowmere.json')), true)['regions'];
    }

    /**
     * The key of the region a point is in: the scrubland between them, or
     * null off the map. Wild patches come first, as they sit beside farms.
     */
    public static function regionAt(float $x, float $y): ?string
    {
        return self::shapeAt($x, $y) ?? (self::reachable($x, $y) ? 'scrubland' : null);
    }

    /** Within the valley's edge. */
    public static function reachable(float $x, float $y): bool
    {
        [$left, $top, $width, $height] = self::REACH;

        return $x >= $left && $x <= $left + $width && $y >= $top && $y <= $top + $height;
    }

    /**
     * How rich the land is at a point: everything grows in full near the
     * farms, and thins out the further you go, down to a quarter.
     */
    public static function richness(float $x, float $y): float
    {
        [$left, $top, $width, $height] = self::HEART;
        $nearest = hypot(max($left - $x, 0, $x - $left - $width), max($top - $y, 0, $y - $top - $height));

        foreach (ValleyBuilder::REGIONS as $key => [, $ring]) {
            if ($ring === 'farm' && ! isset(ValleyBuilder::FIELD_OF[$key]) && isset(self::regions()[$key])) {
                [$hx, $hy] = self::hub($key);
                $nearest = min($nearest, hypot($x - $hx, $y - $hy));
            }
        }

        $far = min(1, max(0, ($nearest - self::NEAR) / (self::FAR - self::NEAR)));

        return round(1 - $far * (1 - self::LEAST), 2);
    }

    /** How rich a region's land is, at its hub. The scrub is everywhere, so it counts as far out. */
    public static function regionRichness(string $key): float
    {
        if (! isset(self::regions()[$key])) {
            return self::LEAST;
        }

        [$x, $y] = self::hub($key);

        return self::richness($x, $y);
    }

    /** The drawn region a point is in, or null in the scrub or off the map. */
    private static function shapeAt(float $x, float $y): ?string
    {
        $regions = self::regions();
        uksort($regions, fn (string $a, string $b) => isset(ValleyBuilder::FIELD_OF[$b]) <=> isset(ValleyBuilder::FIELD_OF[$a]));

        foreach ($regions as $key => $region) {
            if (self::inside($x, $y, $region['polygon'])) {
                return $key;
            }
        }

        return null;
    }

    /** @return array{0: float, 1: float} Where a region's lanes meet and animals gather. */
    public static function hub(string $key): array
    {
        return self::regions()[$key]['hub'] ?? [800.0, 500.0];
    }

    /** @param  list<array{0: float, 1: float}>  $polygon */
    private static function inside(float $x, float $y, array $polygon): bool
    {
        $hit = false;

        for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
            [$xi, $yi] = $polygon[$i];
            [$xj, $yj] = $polygon[$j];

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $hit = ! $hit;
            }
        }

        return $hit;
    }
}
