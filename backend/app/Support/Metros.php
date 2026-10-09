<?php

namespace App\Support;

/**
 * Read access to config/metros.php.
 */
final class Metros
{
    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(config('metros', []));
    }

    /**
     * @return array<string, mixed>
     */
    public static function find(string $code): array
    {
        return config("metros.$code") ?? config('metros.other');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $metros = [];
        foreach (config('metros', []) as $code => $metro) {
            $metros[] = ['code' => $code] + $metro;
        }

        return $metros;
    }
}
