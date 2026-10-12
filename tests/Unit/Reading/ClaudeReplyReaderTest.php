<?php

namespace Tests\Unit\Reading;

use Anthropic\Client;
use App\Enums\ReplyClassification;
use App\Services\Replies\ClaudeReplyReader;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\FakeReplyReader;
use Tests\TestCase;

/**
 * The request we send to read a municipal reply, and how each answer is
 * handled. No network: a fake HTTP client stands in for the API.
 */
class ClaudeReplyReaderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    private function reader(array $reply, int $status = 200): ClaudeReplyReader
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

        return new ClaudeReplyReader(
            client: new Client(apiKey: 'sk-test', requestOptions: ['transporter' => $transporter, 'maxRetries' => 0]),
            model: 'claude-opus-5-5',
            effort: 'medium',
        );
    }

    private function message(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 800, 'output_tokens' => 90],
        ];
    }

    public function test_it_sends_the_reply_with_the_schema_and_reads_the_classification(): void
    {
        $output = FakeReplyReader::output('upheld', ['credit_amount' => 924.0, 'reference' => 'JHB-1']);

        $reading = $this->reader($this->message(json_encode($output)))->read('Municipality: City of Johannesburg', 'RE: Dispute', 'We will credit R924.');

        $this->assertSame(ReplyClassification::Upheld, $reading->classification);
        $this->assertSame('924.00', $reading->creditAmount);
        $this->assertSame('JHB-1', $reading->reference);
        $this->assertSame([800, 90], [$reading->usage->counts[0]->input, $reading->usage->counts[0]->output]);

        $body = json_decode((string) $this->requests[0]->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertContains('rejected', $body['output_config']['format']['schema']['properties']['classification']['enum']);
        $this->assertStringContainsString('We will credit R924.', $body['messages'][0]['content'][0]['text']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $this->requests[0]->getHeaderLine('anthropic-beta'));
    }

    public function test_a_declined_or_failed_request_leaves_the_reply_for_our_team(): void
    {
        $declined = $this->reader($this->message('', 'refusal'))->read('', 'Subject', 'Body');
        $this->assertSame(ReplyClassification::Unread, $declined->classification);
        $this->assertSame(800, $declined->usage->counts[0]->input);

        $failed = $this->reader(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)->read('', 'Subject', 'Body');
        $this->assertSame(ReplyClassification::Unread, $failed->classification);
        $this->assertNull($failed->usage);
    }
}
