<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A nudge about a deadline: the dispute window closing on a flagged bill, or
 * a self-sent dispute that now needs escalating.
 */
class DisputeReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly array $lines,
        public readonly string $actionText,
        public readonly string $actionUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject)->greeting("Hi {$notifiable->name},");

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message->action($this->actionText, $this->actionUrl);
    }
}
