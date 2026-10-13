<?php

namespace App\Services\Replies;

interface ReplyReader
{
    /**
     * Read a reply to a dispute, with its attached letters.
     *
     * @param  string  $context  What the dispute is about, so the reply can be matched to it.
     * @param  list<array{mime: string, contents: string}>  $files  PDFs and images attached to the reply.
     */
    public function read(string $context, string $from, string $subject, string $body, array $files = []): ReplyReading;
}
