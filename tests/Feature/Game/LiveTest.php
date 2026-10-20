<?php

namespace Tests\Feature\Game;

use App\Events\MessageSent;
use App\Events\NoticesSent;
use App\Events\ValleyChanged;
use App\Game\Chat;
use App\Game\Messages;
use App\Game\Notices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Live updates: notices, messages, chat and the map are pushed over the
 * websocket, each only to those allowed to see them.
 */
class LiveTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    public function test_notices_and_messages_pop_up_for_whoever_they_are_for(): void
    {
        Event::fake([NoticesSent::class, MessageSent::class]);
        $from = $this->player();
        $to = $this->player();

        Notices::send([$from->id, $to->id], 'test', 'Hello, valley.');
        app(Messages::class)->send($from, $to, 'Meet me at the barn.');

        Event::assertDispatched(NoticesSent::class, fn (NoticesSent $e) => $e->playerIds === [$from->id, $to->id]
            && collect($e->broadcastOn())->map->name->all() === ["private-player.{$from->id}", "private-player.{$to->id}"]);
        Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->broadcastOn()->name === "private-player.{$to->id}" && $e->senderName === $from->name
            // The chat card that pops up needs who sent it and which message it was.
            && $e->broadcastWith()['sender']['id'] === $from->id && $e->broadcastWith()['id'] > 0);
    }

    public function test_chat_and_actions_tell_the_valley_without_saying_what(): void
    {
        Event::fake([ValleyChanged::class]);
        $player = $this->player();

        app(Chat::class)->post($player, 'valley', 'Morning all.');
        Event::assertDispatched(ValleyChanged::class, fn (ValleyChanged $e) => $e->broadcastAs() === 'chat' && $e->broadcastWith() === ['channel' => 'valley']);

        // A walk that goes through refreshes everyone's map.
        $this->actingAs($player->user)->post(route('play.walk-to'), ['x' => 1100, 'y' => 1000]);
        Event::assertDispatched(ValleyChanged::class, fn (ValleyChanged $e) => $e->broadcastAs() === 'map' && $e->valleyId === $player->valley_id);
    }

    public function test_only_the_animal_and_its_valley_may_listen(): void
    {
        $player = $this->player();
        $other = $this->player();
        $channels = Broadcast::driver()->getChannels();
        $can = fn (string $channel, int $id) => (bool) $channels[$channel]($player->user, $id);

        $this->assertTrue($can('player.{id}', $player->id));
        $this->assertFalse($can('player.{id}', $other->id));
        $this->assertTrue($can('valley.{id}', $player->valley_id));
        $this->assertFalse($can('valley.{id}', $player->valley_id + 1));
    }
}
