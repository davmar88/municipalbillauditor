<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something changed in a valley that every animal in it should see: the
 * map ("map": a raid, a fire, a home, a band, an animal on the move) or a
 * chat channel ("chat", with its key). It carries no details: each animal's
 * page fetches what it's allowed to see.
 */
class ValleyChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $valleyId, public string $what, public ?string $channel = null) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("valley.{$this->valleyId}");
    }

    public function broadcastAs(): string
    {
        return $this->what;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['channel' => $this->channel];
    }
}
