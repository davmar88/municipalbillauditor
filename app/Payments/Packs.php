<?php

namespace App\Payments;

/**
 * The packs of Apples on sale, from config/game.php. Each saves a share of
 * the smallest pack's price per Apple.
 */
final class Packs
{
    /** @return list<array{id: string, apples: int, zar: int, usd: int, save: int}> */
    public static function all(): array
    {
        $packs = config('game.apples.packs');
        $first = reset($packs);
        $base = $first['zar'] / $first['apples'];

        return collect($packs)
            ->map(fn (array $pack, string $id) => [
                'id' => $id,
                'apples' => $pack['apples'],
                'zar' => $pack['zar'],
                'usd' => $pack['usd'],
                'save' => (int) round((1 - ($pack['zar'] / $pack['apples']) / $base) * 100),
            ])
            ->values()
            ->all();
    }

    /** @return array{id: string, apples: int, zar: int, usd: int, save: int}|null */
    public static function find(string $id): ?array
    {
        return collect(self::all())->firstWhere('id', $id);
    }
}
