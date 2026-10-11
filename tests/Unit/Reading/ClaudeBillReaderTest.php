<?php

namespace Tests\Unit\Reading;

use Anthropic\Client;
use App\Services\Reading\BillReadingFailed;
use App\Services\Reading\ClaudeBillReader;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\FakeBillReader;
use Tests\TestCase;

/**
 * The request we send to the Messages API, and how each kind of reply is
 * handled. No network: a fake HTTP client stands in for the API.
 */
class ClaudeBillReaderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private function reader(array $reply, int $status = 200): ClaudeBillReader
    {
        $transporter = new class($this->requests, $reply, $status) implements ClientInterface
        {
            public function __construct(private array &$requests, private array $reply, private int $status) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return new Response($this->status, ['Content-Type' => 'application/json', 'request-id' => 'req_test'], json_encode($this->reply));
            }
        };

        return new ClaudeBillReader(
            client: new Client(apiKey: 'sk-test', requestOptions: ['transporter' => $transporter, 'maxRetries' => 0]),
            model: 'test-model',
            effort: 'medium',
        );
    }

    private function message(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'test-model',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ];
    }

    public function test_it_sends_the_bill_with_the_schema_and_reads_the_reply(): void
    {
        $bill = $this->reader($this->message(json_encode(FakeBillReader::output())))->read('%PDF-1.7 bill', 'application/pdf');

        $this->assertSame('5021 4478 2210', $bill->accountNumber);
        $this->assertSame('2026-10-03', $bill->billDate->toDateString());
        $this->assertSame('562.05', $bill->charges[1]['amount']);
        $this->assertSame([], $bill->warnings());

        $request = $this->requests[0];
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringEndsWith('/v1/messages?beta=true', (string) $request->getUri());
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame('test-model', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame('document', $body['messages'][0]['content'][0]['type']);
        $this->assertSame('application/pdf', $body['messages'][0]['content'][0]['source']['media_type']);
        $this->assertSame(base64_encode('%PDF-1.7 bill'), $body['messages'][0]['content'][0]['source']['data']);
    }

    public function test_photos_go_as_images(): void
    {
        $this->reader($this->message(json_encode(FakeBillReader::output())))->read('JPEG', 'image/jpeg');

        $body = json_decode((string) $this->requests[0]->getBody(), true);
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
    }

    public function test_a_declined_request_needs_the_details_typed_in(): void
    {
        $this->expectException(BillReadingFailed::class);

        $this->reader($this->message('', 'refusal'))->read('JPEG', 'image/jpeg');
    }

    public function test_something_that_is_not_a_bill_is_turned_back(): void
    {
        $this->expectExceptionMessage("This doesn't look like a municipal bill.");

        $this->reader($this->message(json_encode(FakeBillReader::output(['is_municipal_bill' => false]))))->read('JPEG', 'image/jpeg');
    }

    public function test_an_api_error_needs_the_details_typed_in(): void
    {
        $this->expectException(BillReadingFailed::class);

        $this->reader(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)->read('JPEG', 'image/jpeg');
    }

    public function test_other_file_types_are_refused_before_sending(): void
    {
        try {
            $this->reader($this->message('{}'))->read('GIF89a', 'text/plain');
            $this->fail('Expected the reader to refuse a text file.');
        } catch (BillReadingFailed) {
            $this->assertSame([], $this->requests);
        }
    }
}
