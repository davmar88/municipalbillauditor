<?php

namespace App\Mail;

use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The welcome mail a newcomer gets once they've made their animal: who they
 * are, and the first few things to do in the valley.
 */
class Welcome extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public Player $player) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Welcome to the farm, {$this->player->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.welcome', with: [
            'name' => $this->player->name,
            'species' => $this->player->species->label(),
            'talent' => $this->player->species->talent(),
            'valley' => $this->player->valley?->name ?? 'the valley',
            'horseshoes' => (int) config('game.horseshoes.max'),
        ]);
    }
}
