<?php

namespace App\Audit;

final class Text
{
    private const ORDINALS = [2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth', 6 => 'sixth', 7 => 'seventh', 8 => 'eighth'];

    public static function ordinal(int $number): string
    {
        return self::ORDINALS[$number] ?? $number.'th';
    }

    /**
     * "Jul, Aug and Sep".
     *
     * @param  list<string>  $words
     */
    public static function join(array $words): string
    {
        if (count($words) <= 1) {
            return implode('', $words);
        }

        $last = array_pop($words);

        return implode(', ', $words).' and '.$last;
    }
}
