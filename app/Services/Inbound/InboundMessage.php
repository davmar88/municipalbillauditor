<?php

namespace App\Services\Inbound;

use Illuminate\Http\UploadedFile;

/**
 * An email that arrived at one of our addresses, whichever provider
 * delivered it.
 */
final readonly class InboundMessage
{
    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function __construct(
        public string $recipients,
        public string $from,
        public string $subject,
        public string $text,
        public ?string $messageId,
        public ?string $inReplyTo,
        public array $attachments,
    ) {}
}
