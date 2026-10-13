<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/**
 * A dispute email to the municipality: the dispute itself, a follow-up on
 * the same thread, or an escalation. Sent from our address, with replies
 * going to the dispute's own address and the customer copied in.
 */
class DisputeEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $copyTo
     * @param  list<array{disk: string, path: string, name: string, mime: ?string}>  $files
     * @param  list<string>  $references  Earlier Message-IDs on this thread.
     */
    public function __construct(
        public string $mailSubject,
        public string $body,
        public string $replyAddress,
        public array $copyTo,
        public string $messageId,
        public array $files = [],
        public ?string $inReplyTo = null,
        public array $references = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
            cc: array_map(fn (string $email) => new Address($email), $this->copyTo),
            replyTo: [new Address($this->replyAddress)],
            using: [function (Email $message) {
                $headers = $message->getHeaders();
                $headers->addIdHeader('Message-ID', $this->messageId);

                if ($this->inReplyTo !== null) {
                    $headers->addIdHeader('In-Reply-To', $this->inReplyTo);
                    $headers->addIdHeader('References', $this->references !== [] ? $this->references : [$this->inReplyTo]);
                }
            }],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.dispute', with: ['body' => $this->body]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromStorageDisk($file['disk'], $file['path'])
                ->as($file['name'])
                ->withMime($file['mime'] ?? 'application/octet-stream'),
            $this->files,
        );
    }
}
