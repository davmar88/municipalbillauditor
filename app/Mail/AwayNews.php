<?php

namespace App\Mail;

use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/** What happened to an animal while its player was away. */
class AwayNews extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @param  list<string>  $news */
    public function __construct(public Player $player, public array $news) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function envelope(): Envelope
    {
        $count = count($this->news);

        return new Envelope(subject: $count === 1
            ? "{$this->player->name}, something happened on the farm"
            : "{$this->player->name}, {$count} things happened on the farm");
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->stopUrl().'>']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.away-news', with: [
            'name' => $this->player->name,
            'news' => $this->news,
            'stop' => $this->stopUrl(),
        ]);
    }

    private function stopUrl(): string
    {
        return URL::signedRoute('news.off', ['player' => $this->player->id]);
    }
}
