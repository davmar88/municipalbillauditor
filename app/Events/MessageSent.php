<?php

namespace App\Events;

use App\Models\DirectMessage;
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

    public function __construct(public int $recipientId, public int $senderId, public string $senderName, public string $preview) {}

    public static function of(DirectMessage $message, string $senderName): self
    {
        return new self($message->recipient_id, $message->sender_id, $senderName, Str::limit($message->body, 120));
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
        return ['from' => $this->senderId, 'name' => $this->senderName, 'preview' => $this->preview];
    }
}
