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
    /** @var array<string, array{polygon: list<array{0: float, 1: float}>, hub: array{0: float, 1: float}}>|null */
    private static ?array $regions = null;

    /** @return array<string, array{polygon: list<array{0: float, 1: float}>, hub: array{0: float, 1: float}}> */
    public static function regions(): array
    {
        return self::$regions ??= json_decode((string) file_get_contents(resource_path('data/hollowmere.json')), true)['regions'];
    }

    /** The key of the region a point is in, or null off the map. Wild patches come first, as they sit beside farms. */
    public static function regionAt(float $x, float $y): ?string
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
