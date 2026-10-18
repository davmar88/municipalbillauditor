<?php

namespace Tests\Feature\Game;

use App\Game\Chat;
use App\Game\GameException;
use App\Game\Messages;
use App\Game\Translation\Translator;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\MessageReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Private messages: writing to anyone, the inbox and its unread badge,
 * blocks, reports and our team's mute, and translation.
 */
class MessagesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Messages $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->messages = app(Messages::class);
    }

    public function test_any_animal_can_write_to_any_other_in_one_conversation_per_pair(): void
    {
        $ada = $this->player();
        $bo = $this->player();
        $this->faction('Red', $ada);

        $this->actingAs($ada->user)->post(route('play.messages.store', $bo), ['body' => '  Truce at the pond?  '])->assertRedirect();
        $this->messages->send($bo, $ada, 'Deal.');

        $this->assertSame(1, Conversation::count());
        $this->assertSame(['Truce at the pond?', 'Deal.'], DirectMessage::query()->orderBy('id')->pluck('body')->all());
        $this->assertSame(1, Messages::unread($bo));
        $this->assertSame(1, Messages::unread($ada));

        $this->actingAs($bo->user)->get(route('play.messages'))->assertInertia(fn (Assert $page) => $page
            ->component('game/messages')
            ->where('unreadMessages', 1)
            ->has('conversations', 1)
            ->where('conversations.0.animal.name', $ada->name)
            ->where('conversations.0.animal.faction', 'Red')
            ->where('conversations.0.last.body', 'Deal.')
            ->where('conversations.0.last.mine', true)
            ->where('conversations.0.unread', 1));
    }

    public function test_opening_a_conversation_reads_it_and_tells_the_writer(): void
    {
        $ada = $this->player();
        $bo = $this->player();
        $this->messages->send($ada, $bo, 'Hello');

        $this->actingAs($bo->user)->get(route('play.messages.show', $ada))->assertInertia(fn (Assert $page) => $page
            ->component('game/conversation')
            ->where('animal.name', $ada->name)
            ->where('unreadMessages', 0)
            ->has('messages', 1)
            ->where('messages.0.mine', false)
            ->where('cantWrite', null));

        $this->assertSame(0, Messages::unread($bo));

        $this->actingAs($ada->user)->get(route('play.messages.show', $bo))->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.mine', true)
            ->where('messages.0.read', true));
    }

    public function test_a_conversation_with_someone_new_starts_empty_and_you_cannot_write_to_yourself(): void
    {
        $ada = $this->player();
        $bo = $this->player();

        $this->actingAs($ada->user)->get(route('play.messages.show', $bo))->assertInertia(fn (Assert $page) => $page
            ->component('game/conversation')
            ->has('messages', 0));
        $this->assertSame(0, Conversation::count());

        $this->actingAs($ada->user)->get(route('play.messages.show', $ada))->assertRedirect(route('play.messages'));

        $this->expectException(GameException::class);
        $this->messages->send($ada, $ada, 'Me, myself');
    }

    public function test_messages_need_words_and_a_limit(): void
    {
        $ada = $this->player();
        $bo = $this->player();

        foreach (['   ', str_repeat('a', Messages::MAX_LENGTH + 1)] as $body) {
            try {
                $this->messages->send($ada, $bo, $body);
                $this->fail('That message should have been refused.');
            } catch (GameException) {
                $this->assertSame(0, DirectMessage::count());
            }
        }
    }

    public function test_a_block_stops_both_sides_writing_until_it_is_lifted(): void
    {
        $ada = $this->player();
        $bo = $this->player();
        $this->messages->send($bo, $ada, 'Give me your Corn');

        $this->actingAs($ada->user)->post(route('play.messages.block', $bo))->assertRedirect();

        $this->assertSame("{$ada->name} isn't taking messages from you.", Messages::cantWrite($bo, $ada));
        $this->assertSame("You blocked {$bo->name}. Unblock them to write.", Messages::cantWrite($ada, $bo));

        $this->actingAs($bo->user)->post(route('play.messages.store', $ada), ['body' => 'Hello?'])->assertRedirect();
        $this->assertSame(1, DirectMessage::count());

        $this->actingAs($ada->user)->get(route('play.messages'))->assertInertia(fn (Assert $page) => $page
            ->where('conversations.0.blocked', true));

        $this->actingAs($ada->user)->delete(route('play.messages.unblock', $bo))->assertRedirect();
        $this->messages->send($bo, $ada, 'Sorry');
        $this->assertSame(2, DirectMessage::count());
    }

    public function test_an_animal_can_start_twenty_new_conversations_a_day(): void
    {
        $ada = $this->player();
        $friend = $this->player();
        $this->messages->send($ada, $friend, 'First');

        for ($i = 1; $i < Messages::NEW_CONVERSATIONS_PER_DAY; $i++) {
            $this->messages->send($ada, $this->player(), 'Hi');
        }

        try {
            $this->messages->send($ada, $this->player(), 'One more');
            $this->fail('The twenty-first new conversation should wait for tomorrow.');
        } catch (GameException $e) {
            $this->assertStringContainsString('new conversations a day', $e->getMessage());
        }

        $this->messages->send($ada, $friend, 'Still fine with friends');
        $this->assertSame(Messages::NEW_CONVERSATIONS_PER_DAY, Conversation::count());

        $this->travel(1)->days();
        $this->messages->send($ada, $this->player(), 'A new day');
        $this->assertSame(Messages::NEW_CONVERSATIONS_PER_DAY + 1, Conversation::count());
    }

    public function test_the_reader_translates_a_message_once(): void
    {
        $this->app->instance(Translator::class, new class implements Translator
        {
            public int $calls = 0;

            public function available(): bool
            {
                return true;
            }

            public function translate(string $text, string $language): ?string
            {
                $this->calls++;

                return "[{$language}] {$text}";
            }
        });

        $writer = $this->player();
        $reader = $this->player(attributes: ['language' => 'Sesotho']);
        $message = app(Messages::class)->send($writer, $reader, 'Meet me at the mill');

        $this->actingAs($reader->user)->postJson(route('play.messages.translate', $message))
            ->assertOk()
            ->assertJson(['translation' => '[Sesotho] Meet me at the mill']);
        $this->actingAs($reader->user)->postJson(route('play.messages.translate', $message))->assertOk();
        $this->assertSame(1, app(Translator::class)->calls);

        $this->actingAs($reader->user)->get(route('play.messages.show', $writer))->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.translation', '[Sesotho] Meet me at the mill'));

        $this->actingAs($this->player()->user)->postJson(route('play.messages.translate', $message))
            ->assertStatus(422)
            ->assertJson(['message' => "You can't read that message."]);
    }

    public function test_the_reader_reports_a_message_and_only_the_reader_sees_it_was_reported(): void
    {
        $writer = $this->player();
        $reader = $this->player();
        $message = $this->messages->send($writer, $reader, 'You will regret this');

        $this->actingAs($writer->user)->post(route('play.messages.report', $message), ['reason' => 'unkind']);
        $this->assertSame(0, MessageReport::count(), 'Only the reader can report a message.');

        $this->actingAs($reader->user)->post(route('play.messages.report', $message), ['reason' => 'unkind', 'note' => 'Threats', 'block' => true])
            ->assertRedirect();

        $this->assertSame(1, MessageReport::count());
        $this->assertTrue(Messages::blocks($reader, $writer));

        $this->actingAs($reader->user)->get(route('play.messages.show', $writer))->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.reported', true));
        $this->actingAs($writer->user)->get(route('play.messages.show', $reader))->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.reported', false));
    }

    public function test_our_team_hides_a_reported_message_and_mutes_the_writer_in_messages_and_chat(): void
    {
        $writer = $this->player();
        $reader = $this->player();
        $this->messages->send($reader, $writer, 'Nice hat');
        $message = $this->messages->send($writer, $reader, 'Something nasty');
        $report = $this->messages->report($reader, $message, 'unkind');
        $staff = User::factory()->staff()->create();

        $this->actingAs($reader->user)->get(route('staff.reports'))->assertForbidden();

        $this->actingAs($staff)->get(route('staff.reports'))->assertInertia(fn (Assert $page) => $page
            ->component('staff/reports')
            ->has('reports', 1)
            ->where('reports.0.message.body', 'Something nasty')
            ->where('reports.0.before.0.body', 'Nice hat')
            ->where('reports.0.before.0.from', $reader->name));

        $this->actingAs($staff)->post(route('staff.reports.mute', $report), ['days' => 7])->assertRedirect();

        $this->assertNotNull($message->fresh()->hidden_at);
        $this->assertTrue($writer->fresh()->isMuted());
        $this->assertSame('muted', $report->fresh()->outcome);

        $this->actingAs($reader->user)->get(route('play.messages.show', $writer))->assertInertia(fn (Assert $page) => $page
            ->where('messages.1.body', null));

        $this->assertStringStartsWith('Our team has stopped you writing', (string) Messages::cantWrite($writer->fresh(), $reader));

        $this->expectException(GameException::class);
        app(Chat::class)->post($writer->fresh(), Chat::valley(), 'Hello square');
    }

    public function test_our_team_can_let_a_reported_message_stand(): void
    {
        $writer = $this->player();
        $reader = $this->player();
        $message = $this->messages->send($writer, $reader, 'Buy my eggs, cheapest in the valley');
        $report = $this->messages->report($reader, $message, 'spam');
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post(route('staff.reports.dismiss', $report))->assertRedirect();

        $this->assertSame('dismissed', $report->fresh()->outcome);
        $this->assertNull($message->fresh()->hidden_at);
        $this->assertFalse($writer->fresh()->isMuted());

        $this->expectException(GameException::class);
        $this->messages->review($report->fresh(), $staff, 7);
    }

    public function test_finding_an_animal_by_name(): void
    {
        $me = $this->player();
        $this->player(attributes: ['name' => 'Bramble']);
        $this->player(attributes: ['name' => 'Old Bramblewood']);
        $this->player(attributes: ['name' => 'Clover']);

        $this->actingAs($me->user)->get(route('play.messages', ['q' => 'bramble']))->assertInertia(fn (Assert $page) => $page
            ->where('search', 'bramble')
            ->has('found', 2)
            ->where('found.0.name', 'Bramble')
            ->where('found.1.name', 'Old Bramblewood'));

        // A wildcard is just a character to search for.
        $this->actingAs($me->user)->get(route('play.messages', ['q' => '%%']))->assertInertia(fn (Assert $page) => $page
            ->has('found', 0));
    }

    public function test_chat_names_link_to_their_writer(): void
    {
        $writer = $this->player();
        app(Chat::class)->post($writer, Chat::valley(), 'Selling hats');

        $this->actingAs($this->player()->user)->get(route('play.chat'))->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.from.id', $writer->id)
            ->where('silenced', null));
    }
}
