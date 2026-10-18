<?php

namespace App\Payments;

/**
 * What is on sale, from config/game.php: packs of Pineapples bought with US
 * dollars, and packs of Apples bought with Pineapples. Each bigger pack saves
 * a share of the smallest pack's price.
 */
final class Packs
{
    /** @return list<array{id: string, pineapples: int, usd: int, save: int}> */
    public static function pineapples(): array
    {
        return self::priced(config('game.pineapples.packs'), 'pineapples', 'usd');
    }

    /** @return list<array{id: string, apples: int, pineapples: int, save: int}> */
    public static function apples(): array
    {
        return self::priced(config('game.apples.packs'), 'apples', 'pineapples');
    }

    /** @return array{id: string, pineapples: int, usd: int, save: int}|null */
    public static function findPineapples(string $id): ?array
    {
        return collect(self::pineapples())->firstWhere('id', $id);
    }

    /** @return array{id: string, apples: int, pineapples: int, save: int}|null */
    public static function findApples(string $id): ?array
    {
        return collect(self::apples())->firstWhere('id', $id);
    }

    /**
     * @param  array<string, array<string, int>>  $packs
     * @return list<array<string, int|string>>
     */
    private static function priced(array $packs, string $size, string $price): array
    {
        $first = reset($packs);
        $base = $first[$price] / $first[$size];

        return collect($packs)
            ->map(fn (array $pack, string $id) => [
                'id' => $id,
                ...$pack,
                'save' => (int) round((1 - ($pack[$price] / $pack[$size]) / $base) * 100),
            ])
            ->values()
            ->all();
    }
}
