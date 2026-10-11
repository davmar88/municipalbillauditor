<?php

namespace App\Services\Reading;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Reads a bill photo or PDF with Claude, using structured outputs so the
 * reply always has the BillSchema shape.
 *
 * Server-side fallbacks are on: if the model declines a request for policy
 * reasons, the API retries it on the default fallback model in the same call.
 */
class ClaudeBillReader implements BillReader
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort,
        private readonly int $maxTokens = 16000,
    ) {}

    public function read(string $contents, string $mimeType): ExtractedBill
    {
        $source = ['type' => 'base64', 'mediaType' => $mimeType, 'data' => base64_encode($contents)];

        $document = match (true) {
            $mimeType === 'application/pdf' => ['type' => 'document', 'source' => $source],
            in_array($mimeType, self::IMAGE_TYPES, true) => ['type' => 'image', 'source' => $source],
            default => throw new BillReadingFailed('We can read photos (JPEG, PNG, WebP) and PDFs. Please send the bill in one of those.'),
        };

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $this->maxTokens,
                messages: [[
                    'role' => 'user',
                    'content' => [$document, ['type' => 'text', 'text' => BillSchema::INSTRUCTIONS]],
                ]],
                model: $this->model,
                system: BillSchema::SYSTEM,
                outputConfig: [
                    'effort' => $this->effort,
                    'format' => ['type' => 'json_schema', 'schema' => BillSchema::schema()],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AnthropicException $e) {
            Log::warning('Bill reading request failed', ['error' => $e->getMessage()]);

            throw new BillReadingFailed("We couldn't reach the bill reader just now, so this bill needs its details typed in.", previous: $e);
        }

        $stopReason = $message->stopReason instanceof \BackedEnum ? $message->stopReason->value : $message->stopReason;

        if ($stopReason === 'refusal') {
            Log::warning('Bill reading was declined', ['category' => $message->stopDetails?->category]);

            throw new BillReadingFailed("This bill couldn't be read automatically, so it needs its details typed in.");
        }

        if ($stopReason === 'max_tokens') {
            throw new BillReadingFailed('This bill was too long to read automatically, so it needs its details typed in.');
        }

        foreach ($message->content as $block) {
            if ($block->type !== 'text') {
                continue;
            }

            try {
                $data = json_decode($block->text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new BillReadingFailed("We couldn't make sense of this bill automatically, so it needs its details typed in.", previous: $e);
            }

            if (! is_array($data)) {
                break;
            }

            $bill = ExtractedBill::fromArray($data);

            if (! $bill->isMunicipalBill) {
                throw new BillReadingFailed("This doesn't look like a municipal bill. If it is one, type in its details.");
            }

            return $bill;
        }

        throw new BillReadingFailed("We couldn't make sense of this bill automatically, so it needs its details typed in.");
    }
}
