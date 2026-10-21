<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which country an internet address is in, from DB-IP's free country list
 * kept in the ip_countries table (php artisan geo:update). Nothing leaves
 * the server.
 */
final class Geo
{
    /** Two letters, such as ZA, or null when the address isn't on the list. */
    public static function country(?string $ip): ?string
    {
        $packed = $ip === null ? false : @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        // IPv4 is kept as 4 bytes, IPv6 as 16: compare like with like.
        return Cache::remember('geo:'.bin2hex($packed), now()->addDay(), function () use ($packed) {
            $row = DB::table('ip_countries')
                ->where('ip_from', '<=', $packed)
                ->whereRaw('LENGTH(ip_from) = ?', [strlen($packed)])
                ->orderByDesc('ip_from')
                ->first(['ip_to', 'country']);

            return $row !== null && strcmp($packed, $row->ip_to) <= 0 && $row->country !== 'ZZ' ? $row->country : null;
        });
    }

    /** The country's name in English, such as South Africa. */
    public static function name(?string $code): string
    {
        if ($code === null) {
            return 'Unknown';
        }

        return class_exists(\Locale::class) ? (\Locale::getDisplayRegion('-'.$code, 'en') ?: $code) : $code;
    }

    /** The country's flag, from its two letters. */
    public static function flag(?string $code): string
    {
        if ($code === null || ! preg_match('/^[A-Z]{2}$/', $code)) {
            return '🏳️';
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65);
    }
}
