<?php

namespace Tests\Support;

use App\Services\Claude\ClaudeUsage;
use App\Services\Replies\ReplyReader;
use App\Services\Replies\ReplyReading;

/**
 * Stands in for Claude reading a municipal reply: returns a fixed reading.
 */
class FakeReplyReader implements ReplyReader
{
    /** @var list<array{context: string, subject: string, body: string}> */
    public array $read = [];

    /**
     * @param  array<string, mixed>  $result  The structured output Claude would return.
     */
    public function __construct(private readonly array $result, private readonly ?ClaudeUsage $usage = null) {}

    public function read(string $context, string $subject, string $body): ReplyReading
    {
        $this->read[] = compact('context', 'subject', 'body');

        return ReplyReading::fromArray($this->result, $this->usage);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function output(string $classification, array $overrides = []): array
    {
        return [
            'classification' => $classification,
            'reference' => null,
            'credit_amount' => null,
            'reason' => null,
            'information_requested' => null,
            'summary' => 'The municipality wrote back about your dispute.',
            'customer_action' => null,
            ...$overrides,
        ];
    }
}
