<?php

namespace App\Support;

/**
 * Bill file size limits, in one place.
 *
 * The API accepts bill files up to 10 MB (docs/api.md). PHP refuses larger
 * uploads before Laravel sees them, and its usual defaults (2 MB per file,
 * 8 MB per request) are far below that, so ordinary phone photos would fail.
 * The PHP limits below sit above the API's own limit so that a slightly
 * bigger file still reaches validation and gets the friendly "too big"
 * message. They are applied by `php artisan serve` (App\Console\Commands\
 * ServeCommand) and by public/.user.ini under PHP-FPM; see the README for
 * other servers.
 */
final class UploadLimits
{
    /** Largest bill file the API accepts, in kilobytes (10 MB). */
    public const BILL_FILE_MAX_KB = 10240;

    public const BILL_FILE_TOO_BIG = 'The file is too big. The limit is 10 MB.';

    /**
     * PHP settings the server needs to receive a 10 MB bill file.
     *
     * @var array<string, string>
     */
    public const PHP_INI = [
        'upload_max_filesize' => '12M',
        'post_max_size' => '16M',
    ];

    /**
     * The PHP_INI settings as command-line flags: ['-d', 'upload_max_filesize=12M', ...].
     *
     * @return list<string>
     */
    public static function phpFlags(): array
    {
        $flags = [];
        foreach (self::PHP_INI as $name => $value) {
            $flags[] = '-d';
            $flags[] = $name.'='.$value;
        }

        return $flags;
    }

    /**
     * Converts a php.ini size ("12M", "2048K", "1G", "0") to bytes.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
