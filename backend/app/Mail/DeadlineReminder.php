<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Plain-text reminder of deadlines due soon. Contains no account numbers or
 * bill contents.
 */
class DeadlineReminder extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $deadlines
     */
    public function __construct(public User $user, public array $deadlines) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: count($this->deadlines) === 1
                ? 'A municipal bill deadline is coming up'
                : 'Municipal bill deadlines are coming up',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.deadline-reminder',
            with: [
                'name' => $this->user->name,
                'items' => array_map(fn (array $d) => [
                    'label' => $d['label'],
                    'property' => $d['property_nickname'],
                    'due' => Carbon::parse($d['due_on'])->format('j M Y'),
                ], $this->deadlines),
            ],
        );
    }
}
