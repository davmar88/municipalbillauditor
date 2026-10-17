<?php

namespace App\Game;

/**
 * Rosettes count as experience. Level 2 needs 50 and each level after needs
 * 50 more than the last, so level 10 takes 2,250.
 */
final class Levels
{
    public static function threshold(int $level): int
    {
        return 25 * $level * ($level - 1);
    }

    public static function forRosettes(int $rosettes): int
    {
        $level = 1;

        while (self::threshold($level + 1) <= $rosettes) {
            $level++;
        }

        return $level;
    }
}
