<?php

namespace App\Services\Replies;

/**
 * Used when no Anthropic API key is configured: every reply waits for our
 * team to read it.
 */
class ManualReplyReader implements ReplyReader
{
    public function read(string $context, string $from, string $subject, string $body, array $files = []): ReplyReading
    {
        return ReplyReading::unread('Automatic reading is switched off, so our team will read this reply.');
    }
}
