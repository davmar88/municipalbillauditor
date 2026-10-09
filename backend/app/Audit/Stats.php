<?php

namespace App\Audit;

final class Stats
{
    /**
     * @param  iterable<int|float>  $values
     */
    public static function median(iterable $values): ?float
    {
        $sorted = [];
        foreach ($values as $value) {
            $sorted[] = (float) $value;
        }
        if ($sorted === []) {
            return null;
        }
        sort($sorted);
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }
}
