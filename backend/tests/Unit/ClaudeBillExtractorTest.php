<?php

namespace Tests\Unit;

use Anthropic\Client;
use App\Extraction\BillExtractionFailed;
use App\Extraction\ClaudeBillExtractor;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Exercises the real extractor against a mocked HTTP transport. No request
 * ever leaves the process.
 */
class ClaudeBillExtractorTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    private function extractor(string $stopReason, ?string $text): ClaudeBillExtractor
    {
        $content = $text === null ? [] : [['type' => 'text', 'text' => $text]];
        $body = json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]);

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $body),
        ]));
        $this->sent = [];
        $stack->push(Middleware::history($this->sent));

        $client = new Client(
            apiKey: 'test-key',
            baseUrl: 'https://api.anthropic.test',
            requestOptions: ['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0],
        );

        return new ClaudeBillExtractor('test-key', 'claude-opus-5-5', 16000, $client);
    }

    private function validOutput(): string
    {
        return json_encode([
            'bill_date' => '2026-09-25',
            'period_start' => '2026-08-20',
            'period_end' => '2026-09-19',
            'due_date' => 'not a date',
            'total_cents' => 412350,
            'line_items' => [[
                'service' => 'water',
                'description' => 'Water consumption',
                'tariff_category' => 'Residential',
                'reading_type' => 'estimated',
                'previous_reading' => 1203.0,
                'current_reading' => 1241.0,
                'consumption' => 38.0,
                'unit' => 'kl',
                'amount_cents' => 152340,
            ]],
        ]);
    }

    public function test_sends_the_image_as_base64_with_a_json_schema_and_parses_the_result(): void
    {
        $bill = $this->extractor('end_turn', $this->validOutput())->extract('fake-jpeg-bytes', 'image/jpeg');

        $this->assertSame('2026-09-25', $bill->billDate);
        $this->assertSame('2026-08-20', $bill->periodStart);
        $this->assertNull($bill->dueDate);
        $this->assertSame(412350, $bill->totalCents);
        $this->assertSame('estimated', $bill->lineItems[0]['reading_type']);

        $this->assertCount(1, $this->sent);
        $request = $this->sent[0]['request'];
        $this->assertSame('/v1/messages', $request->getUri()->getPath());
        $payload = json_decode((string) $request->getBody(), true);

        $this->assertSame('claude-opus-5-5', $payload['model']);
        $this->assertArrayNotHasKey('thinking', $payload);
        $this->assertSame('json_schema', $payload['output_config']['format']['type']);
        $this->assertFalse($payload['output_config']['format']['schema']['additionalProperties']);
        $image = $payload['messages'][0]['content'][0];
        $this->assertSame('image', $image['type']);
        $this->assertSame(['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode('fake-jpeg-bytes')], $image['source']);
    }

    public function test_sends_pdfs_as_document_blocks(): void
    {
        $this->extractor('end_turn', $this->validOutput())->extract('%PDF-1.7', 'application/pdf');

        $payload = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $document = $payload['messages'][0]['content'][0];
        $this->assertSame('document', $document['type']);
        $this->assertSame('application/pdf', $document['source']['media_type']);
    }

    public function test_a_refusal_is_an_extraction_failure(): void
    {
        $this->expectException(BillExtractionFailed::class);
        $this->expectExceptionMessage('refusal');

        $this->extractor('refusal', null)->extract('bytes', 'image/png');
    }

    public function test_running_out_of_tokens_is_an_extraction_failure(): void
    {
        $this->expectException(BillExtractionFailed::class);
        $this->expectExceptionMessage('max_tokens');

        $this->extractor('max_tokens', '{"bill_date":')->extract('bytes', 'image/png');
    }

    public function test_unsupported_file_types_fail_without_calling_the_api(): void
    {
        $extractor = $this->extractor('end_turn', $this->validOutput());

        try {
            $extractor->extract('bytes', 'image/heic');
            $this->fail('Expected a failure.');
        } catch (BillExtractionFailed $e) {
            $this->assertSame('unsupported_file_type', $e->getMessage());
        }
        $this->assertCount(0, $this->sent);
    }

    public function test_is_only_available_with_an_api_key(): void
    {
        $this->assertFalse((new ClaudeBillExtractor(null, 'claude-opus-5-5'))->isAvailable());
        $this->assertFalse((new ClaudeBillExtractor('', 'claude-opus-5-5'))->isAvailable());
        $this->assertTrue((new ClaudeBillExtractor('sk-test', 'claude-opus-5-5'))->isAvailable());
    }
}
