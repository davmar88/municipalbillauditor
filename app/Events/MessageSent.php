<?php

namespace App\Events;

use App\Game\Messages;
use App\Models\DirectMessage;
use App\Models\Player;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/** A private message, popping up for whoever it's for. */
class MessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $sender  The sender's card, as the chat window shows it. */
    public function __construct(public int $recipientId, public int $senderId, public string $senderName, public string $preview, public int $messageId = 0, public array $sender = []) {}

    public static function of(DirectMessage $message, Player $from): self
    {
        return new self($message->recipient_id, $message->sender_id, $from->name, Str::limit($message->body, 120), $message->id, Messages::card($from));
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("player.{$this->recipientId}");
    }

    public function broadcastAs(): string
    {
        return 'message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->messageId, 'from' => $this->senderId, 'name' => $this->senderName, 'preview' => $this->preview, 'sender' => $this->sender];
    }
}
