<?php

namespace App\Console\Commands;

use App\Support\UploadLimits;
use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `php artisan serve` with PHP upload limits big enough for a 10 MB bill.
 *
 * Laravel's serve command starts `php -S` with the system php.ini, which
 * usually allows only 2 MB per file and 8 MB per request, and it does not
 * pass on `php -d` flags. Phone photos of bills are often 2 to 5 MB, so
 * this command adds the limits from App\Support\UploadLimits itself.
 */
#[AsCommand(name: 'serve')]
class ServeCommand extends BaseServeCommand
{
    /**
     * @return list<string>
     */
    protected function serverCommand()
    {
        return self::withUploadLimits(parent::serverCommand());
    }

    /**
     * Inserts the upload limit flags right after the PHP binary.
     *
     * @param  list<string>  $command  [php binary, '-S', host:port, router]
     * @return list<string>
     */
    public static function withUploadLimits(array $command): array
    {
        array_splice($command, 1, 0, UploadLimits::phpFlags());

        return $command;
    }
}
