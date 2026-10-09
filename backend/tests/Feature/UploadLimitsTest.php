<?php

namespace Tests\Feature;

use App\Console\Commands\ServeCommand;
use App\Support\UploadLimits;
use Illuminate\Contracts\Console\Kernel;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

class UploadLimitsTest extends TestCase
{
    public function test_php_limits_leave_room_for_a_10_mb_bill(): void
    {
        $billMax = UploadLimits::BILL_FILE_MAX_KB * 1024;
        $this->assertSame(10 * 1024 * 1024, $billMax, 'docs/api.md allows bill files up to 10 MB.');

        $upload = UploadLimits::iniBytes(UploadLimits::PHP_INI['upload_max_filesize']);
        $post = UploadLimits::iniBytes(UploadLimits::PHP_INI['post_max_size']);

        // Above the API's own limit, so a slightly bigger file still gets the
        // friendly validation message instead of being dropped by PHP.
        $this->assertGreaterThan($billMax, $upload);
        // The request also carries the other form fields and multipart framing.
        $this->assertGreaterThanOrEqual($upload + 1024 * 1024, $post);
    }

    public function test_artisan_serve_starts_php_with_the_upload_limits(): void
    {
        $command = $this->app->make(Kernel::class)->all()['serve'];
        $this->assertInstanceOf(ServeCommand::class, $command);

        $command->setInput(new ArrayInput(['--host' => '127.0.0.1', '--port' => '8000'], $command->getDefinition()));
        $serverCommand = (new ReflectionMethod($command, 'serverCommand'))->invoke($command);

        $this->assertSame(
            ['-d', 'upload_max_filesize=12M', '-d', 'post_max_size=16M', '-S', '127.0.0.1:8000'],
            array_slice($serverCommand, 1, 6),
        );
    }

    public function test_user_ini_for_php_fpm_matches_the_limits(): void
    {
        $ini = parse_ini_file(public_path('.user.ini'));

        $this->assertSame(UploadLimits::PHP_INI, $ini);
    }

    public function test_ini_sizes_are_converted_to_bytes(): void
    {
        $this->assertSame(12 * 1024 * 1024, UploadLimits::iniBytes('12M'));
        $this->assertSame(12 * 1024 * 1024, UploadLimits::iniBytes('12m'));
        $this->assertSame(2048 * 1024, UploadLimits::iniBytes('2048K'));
        $this->assertSame(1024 * 1024 * 1024, UploadLimits::iniBytes('1G'));
        $this->assertSame(500, UploadLimits::iniBytes('500'));
        $this->assertSame(0, UploadLimits::iniBytes(''));
    }
}
