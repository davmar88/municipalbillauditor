<?php

namespace Tests\Support;

use App\Extraction\BillExtractionFailed;
use App\Extraction\BillExtractor;
use App\Extraction\ExtractedBill;
use Throwable;

/**
 * Stand-in for the Claude extractor. Unavailable (as if no API key were
 * set) until a result or failure is configured.
 */
class FakeBillExtractor implements BillExtractor
{
    public bool $available = false;

    public ?ExtractedBill $result = null;

    public ?Throwable $exception = null;

    /** @var list<array{mime: string, bytes: int}> */
    public array $calls = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function extract(string $contents, string $mimeType): ExtractedBill
    {
        $this->calls[] = ['mime' => $mimeType, 'bytes' => strlen($contents)];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ?? throw BillExtractionFailed::because('no_result');
    }

    public function willReturn(ExtractedBill $result): static
    {
        $this->available = true;
        $this->result = $result;
        $this->exception = null;

        return $this;
    }

    public function willFail(?Throwable $exception = null): static
    {
        $this->available = true;
        $this->exception = $exception ?? BillExtractionFailed::because('refusal');

        return $this;
    }
}
