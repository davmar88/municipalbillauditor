<?php

namespace App\Game;

/**
 * Luck that can be replayed. Each battle, uprising or crime stores a seed,
 * and the same seed always gives the same rolls, so a disputed result can
 * be checked.
 */
final class Dice
{
    public static function seed(): int
    {
        return random_int(1, 2_000_000_000);
    }

    /** A number between $min and $max, fixed by the seed and the salt. */
    public static function roll(int $seed, string $salt, float $min = 0.0, float $max = 1.0): float
    {
        $fraction = crc32($seed.':'.$salt) / 0xFFFFFFFF;

        return $min + $fraction * ($max - $min);
    }
}
