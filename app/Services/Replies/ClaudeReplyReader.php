<?php

namespace App\Services\Replies;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use App\Services\Claude\ClaudeUsage;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Reads a municipality's reply with Claude, in the same way ClaudeBillReader
 * reads bills: structured outputs for the ReplySchema shape, and
 * server-side fallbacks on so a declined request is retried on the default
 * fallback model in the same call.
 *
 * A reply that can't be read comes back as unread, for our team, never as
 * an exception: a dispute must not lose a reply.
 */
class ClaudeReplyReader implements ReplyReader
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort,
        private readonly int $maxTokens = 4000,
    ) {}

    public function read(string $context, string $from, string $subject, string $body, array $files = []): ReplyReading
    {
        // Attached letters go first, as documents and images, the way bills
        // are sent: the answer is often only in the attachment.
        $attachments = [];

        foreach ($files as $file) {
            $source = ['type' => 'base64', 'mediaType' => $file['mime'], 'data' => base64_encode($file['contents'])];

            $attachments[] = match (true) {
                $file['mime'] === 'application/pdf' => ['type' => 'document', 'source' => $source],
                in_array($file['mime'], self::IMAGE_TYPES, true) => ['type' => 'image', 'source' => $source],
                default => null,
            };
        }

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $this->maxTokens,
                messages: [[
                    'role' => 'user',
                    'content' => [...array_values(array_filter($attachments)), ['type' => 'text', 'text' => implode("\n\n", [
                        "The dispute:\n{$context}",
                        "The reply.\nFrom: {$from}\nSubject: {$subject}\n\n".mb_substr($body, 0, 20000),
                        ReplySchema::INSTRUCTIONS,
                    ])]],
                ]],
                model: $this->model,
                system: ReplySchema::SYSTEM,
                outputConfig: [
                    'effort' => $this->effort,
                    'format' => ['type' => 'json_schema', 'schema' => ReplySchema::schema()],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AnthropicException $e) {
            Log::warning('Reply reading request failed', ['error' => $e->getMessage()]);

            return ReplyReading::unread("We couldn't read this reply automatically, so our team will.");
        }

        $usage = ClaudeUsage::fromMessage($message, $this->model);
        $stopReason = $message->stopReason instanceof \BackedEnum ? $message->stopReason->value : $message->stopReason;

        if ($stopReason === 'refusal' || $stopReason === 'max_tokens') {
            return new ReplyReading(...[...get_object_vars(ReplyReading::unread("We couldn't read this reply automatically, so our team will.")), 'usage' => $usage]);
        }

        foreach ($message->content as $block) {
            if ($block->type !== 'text') {
                continue;
            }

            try {
                $data = json_decode($block->text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                break;
            }

            if (is_array($data)) {
                return ReplyReading::fromArray($data, $usage);
            }
        }

        return new ReplyReading(...[...get_object_vars(ReplyReading::unread("We couldn't read this reply automatically, so our team will.")), 'usage' => $usage]);
    }
}
