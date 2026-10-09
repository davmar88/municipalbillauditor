<?php

namespace Tests\Feature;

use App\Console\Commands\ServeCommand;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Uploads real bill files to a real PHP development server.
 *
 * The other upload tests use fake files, which skip PHP's own upload limits
 * (upload_max_filesize, post_max_size). This one starts `php -S` exactly as
 * `php artisan serve` does and sends real multipart requests, so it fails if
 * the server would turn away an ordinary 2 to 10 MB phone photo.
 *
 * The server gets its own SQLite database and storage folder, and is always
 * stopped at the end.
 */
#[Group('dev-server')]
class DevServerUploadTest extends TestCase
{
    private string $dir;

    private ?Process $server = null;

    private Client $http;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/mba-dev-server-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir.'/storage/app/private');
        File::ensureDirectoryExists($this->dir.'/storage/framework/views');
        File::ensureDirectoryExists($this->dir.'/storage/logs');
        File::ensureDirectoryExists($this->dir.'/files');
        touch($this->dir.'/database.sqlite');
    }

    protected function tearDown(): void
    {
        // Belt and braces: deleting the account also deletes its files.
        if ($this->token !== null && $this->server?->isRunning()) {
            try {
                $this->http->delete('me', [
                    'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token],
                    'json' => ['password' => 'a-long-password'],
                ]);
            } catch (\Throwable) {
                // The server is stopped below either way.
            }
        }

        $this->server?->stop(5);
        $this->server = null;
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_dev_server_accepts_bill_photos_up_to_10_mb(): void
    {
        $this->startServer();
        $token = $this->registerAndGetToken();
        $propertyId = $this->api('POST', '/properties', $token, [
            'nickname' => 'Sunset Court',
            'metro' => 'johannesburg',
            'account_number' => '5501234567',
            'address' => '12 Example Rd, Melville',
            'property_type' => 'sectional_title',
        ])['data']['id'];

        // Ordinary phone photo sizes: above PHP's usual 2 MB default.
        foreach ([3, 9] as $mb) {
            $response = $this->upload($propertyId, $token, $this->png($mb * 1000 * 1000));
            $body = json_decode((string) $response->getBody(), true);

            $this->assertSame(201, $response->getStatusCode(), "A {$mb} MB photo should upload: ".$response->getBody());
            $this->assertTrue($body['data']['has_file']);
            $this->assertSame('image/png', $body['data']['file_mime']);
            $this->assertSame('needs_review', $body['data']['status']);
        }

        $tooBig = ['message' => 'The file is too big. The limit is 10 MB.', 'errors' => ['file' => ['The file is too big. The limit is 10 MB.']]];

        // 11 MB: PHP accepts it, the API's own 10 MB rule turns it away.
        // 14 MB: over upload_max_filesize, so PHP drops the file.
        // 20 MB: over post_max_size, so PHP drops the whole request.
        foreach ([11, 14, 20] as $mb) {
            $response = $this->upload($propertyId, $token, $this->png($mb * 1000 * 1000));

            $this->assertSame(422, $response->getStatusCode(), "A {$mb} MB photo should be refused: ".$response->getBody());
            $this->assertSame($tooBig, json_decode((string) $response->getBody(), true));
        }

        $bills = $this->api('GET', "/properties/{$propertyId}/bills", $token)['data'];
        $this->assertCount(2, $bills);
        // The server stored its files in its own storage folder, not the app's.
        $this->assertCount(2, glob($this->dir.'/storage/app/private/bills/*'));
    }

    private function startServer(): void
    {
        $port = $this->freePort();
        $command = ServeCommand::withUploadLimits([
            PHP_BINARY,
            '-S',
            '127.0.0.1:'.$port,
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
        ]);
        // Test isolation only: Laravel reads LARAVEL_STORAGE_PATH from $_ENV,
        // which the built-in server fills only with this setting.
        array_splice($command, 1, 0, ['-d', 'variables_order=EGPCS']);

        $this->server = new Process($command, public_path(), [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->dir.'/database.sqlite',
            'DB_URL' => '',
            'LARAVEL_STORAGE_PATH' => $this->dir.'/storage',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'null',
            'ANTHROPIC_API_KEY' => '',
        ]);

        $migrate = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), $this->server->getEnv());
        $migrate->mustRun();

        $this->server->start();
        $this->http = new Client([
            'base_uri' => 'http://127.0.0.1:'.$port.'/api/v1/',
            'http_errors' => false,
            'proxy' => '',
            'timeout' => 30,
        ]);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $port, $errno, $error, 0.2)) {
                return;
            }
            if (! $this->server->isRunning()) {
                break;
            }
            usleep(50_000);
        }

        $this->fail('The PHP development server did not start: '.$this->server->getErrorOutput());
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function registerAndGetToken(): string
    {
        return $this->token = $this->api('POST', '/auth/register', null, [
            'name' => 'Thandi M',
            'email' => 'thandi@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            'popia_consent' => true,
        ])['token'];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, ?string $token, array $body = []): array
    {
        $response = $this->http->request($method, ltrim($path, '/'), [
            'headers' => array_filter(['Accept' => 'application/json', 'Authorization' => $token ? 'Bearer '.$token : null]),
            ...($body === [] ? [] : ['json' => $body]),
        ]);
        $this->assertLessThan(300, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true);
    }

    private function upload(int $propertyId, string $token, string $path): ResponseInterface
    {
        return $this->http->post("properties/{$propertyId}/bills", [
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token],
            'multipart' => [[
                'name' => 'file',
                'contents' => fopen($path, 'rb'),
                'filename' => 'IMG_2041.png',
                'headers' => ['Content-Type' => 'image/png'],
            ]],
        ]);
    }

    /**
     * A real PNG (1×1 pixel) padded to the given size, like a large photo.
     */
    private function png(int $bytes): string
    {
        $path = $this->dir.'/files/'.$bytes.'.png';
        if (! file_exists($path)) {
            $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
            file_put_contents($path, $pixel.str_repeat("\0", $bytes - strlen($pixel)));
        }

        return $path;
    }
}
