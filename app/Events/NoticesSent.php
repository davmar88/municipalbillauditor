<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A notice just landed in some animals' bells: each sees it pop up. */
class NoticesSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** @param  list<int>  $playerIds */
    public function __construct(public array $playerIds, public string $kind, public string $text, public ?string $url) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("player.{$id}"), $this->playerIds);
    }

    public function broadcastAs(): string
    {
        return 'notice';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'text' => $this->text, 'url' => $this->url];
    }
}
