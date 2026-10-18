<?php

namespace Tests\Feature\Game;

use App\Enums\Species;
use App\Game\Chat;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\LawTemplate;
use App\Game\Puzzle;
use App\Game\Tick;
use App\Game\Translation\Translator;
use App\Models\ChatMessage;
use App\Models\ChatTranslation;
use App\Models\Law;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Chat and translation, the half-hourly tick, the season's end, the daily
 * puzzle and the public pages.
 */
class ChatTickAndPuzzleTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    public function test_factions_chat_inside_and_with_each_other_but_not_in_strangers_channels(): void
    {
        $red = $this->player();
        $blue = $this->player();
        $green = $this->player();
        $redFaction = $this->faction('Red', $red);
        $blueFaction = $this->faction('Blue', $blue);
        $this->faction('Green', $green);
        $chat = app(Chat::class);

        $between = Chat::between($redFaction->id, $blueFaction->id);
        $chat->post($red->fresh(), $between, 'Truce until Friday?');

        $this->assertTrue($chat->canRead($blue->fresh(), $between));
        $this->assertFalse($chat->canRead($green->fresh(), $between));
        $this->assertFalse($chat->canRead($green->fresh(), Chat::faction($redFaction->id)));
        $this->assertCount(4, $chat->channels($red->fresh()), 'The square, its own channel, and one with each other faction.');

        $this->expectException(GameException::class);
        $chat->post($green->fresh(), $between, 'Let me in');
    }

    public function test_a_message_is_translated_into_the_readers_language_once(): void
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
        $reader = $this->player(attributes: ['language' => 'isiZulu']);
        $message = app(Chat::class)->post($writer, Chat::valley(), 'Hold the gate tonight');

        $this->actingAs($reader->user)->postJson(route('play.chat.translate', $message))
            ->assertOk()
            ->assertJson(['translation' => '[isiZulu] Hold the gate tonight']);
        $this->actingAs($reader->user)->postJson(route('play.chat.translate', $message))->assertOk();

        $this->assertSame(1, app(Translator::class)->calls);
        $this->assertSame(1, ChatTranslation::count());
    }

    public function test_without_a_key_translation_is_switched_off(): void
    {
        $message = app(Chat::class)->post($this->player(), Chat::valley(), 'Sawubona');

        $this->actingAs($this->player()->user)->postJson(route('play.chat.translate', $message))
            ->assertStatus(422)
            ->assertJson(['message' => "Translation isn't switched on yet."]);
    }

    public function test_the_chat_page_shows_the_channel(): void
    {
        $player = $this->player();
        app(Chat::class)->post($player, Chat::valley(), 'Hello valley');

        $this->actingAs($player->user)->get(route('play.chat'))->assertInertia(fn (Assert $page) => $page
            ->component('game/chat')
            ->where('channel', 'valley')
            ->has('messages', 1)
            ->where('messages.0.body', 'Hello valley'));

        $this->actingAs($player->user)->post(route('play.chat.store'), ['channel' => 'valley', 'body' => 'Second'])->assertRedirect();
        $this->assertSame(2, ChatMessage::count());
    }

    public function test_opening_pages_does_not_use_up_the_limit_on_writing(): void
    {
        $player = $this->player();
        $friend = $this->player();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($player->user)->get(route('play'))->assertOk();
        }

        $this->actingAs($player->user)->post(route('play.chat.store'), ['channel' => 'valley', 'body' => 'Hello valley'])->assertRedirect();
        $this->actingAs($player->user)->post(route('play.messages.store', $friend), ['body' => 'Hello friend'])->assertRedirect();
        $this->assertSame(1, ChatMessage::count());

        // Chat keeps its own limit of 20 a minute.
        for ($i = 1; $i < 20; $i++) {
            $this->actingAs($player->user)->post(route('play.chat.store'), ['channel' => 'valley', 'body' => "Message {$i}"])->assertRedirect();
        }

        $this->actingAs($player->user)->post(route('play.chat.store'), ['channel' => 'valley', 'body' => 'One too many'])->assertTooManyRequests();
    }

    public function test_rations_go_out_each_morning_and_favoured_species_get_a_fifth_more(): void
    {
        $this->travelTo(Clock::now()->setTime(5, 0));
        $ruler = $this->player(Species::Dog);
        $house = $this->faction('The House', $ruler);
        $this->hold($house, 'farmhouse');
        $house->forceFill(['treasury' => 1000])->save();
        $pig = $this->player(Species::Pig);
        $season = $this->season();
        $season->forceFill(['tax_collected' => 220])->save();
        Law::create(['season_id' => $season->id, 'slot' => 1, 'template' => LawTemplate::Rations, 'value' => 50, 'target' => 'pig']);

        app(Tick::class)->run($this->valley);
        $this->assertSame(100, $pig->fresh()->corn, 'Rations wait for 06:00.');

        $this->travelTo(Clock::now()->setTime(6, 30));
        app(Tick::class)->run($this->valley);

        // 110 Corn split 1.0 : 1.2 between the dog and the pig.
        $this->assertSame(100 + 60, $pig->fresh()->corn);
        $this->assertSame(100 + 50, $ruler->fresh()->corn);
        $this->assertSame(1000 - 110, $house->fresh()->treasury);
        $this->assertSame(0, $season->fresh()->tax_collected);

        app(Tick::class)->run($this->valley);
        $this->assertSame(160, $pig->fresh()->corn, 'Once a day.');
    }

    public function test_the_night_watch_costs_fifty_corn_a_tick(): void
    {
        $house = $this->faction('The House', $this->player());
        $this->hold($house, 'farmhouse');
        $house->forceFill(['treasury' => 120])->save();
        Law::create(['season_id' => $this->season()->id, 'slot' => 1, 'template' => LawTemplate::NightWatch]);

        app(Tick::class)->run($this->valley);
        app(Tick::class)->run($this->valley);
        $this->assertSame(70, $house->fresh()->treasury, 'Paid once per half hour, however often the tick runs.');

        $this->travel(31)->minutes();
        app(Tick::class)->run($this->valley);
        $this->travel(31)->minutes();
        app(Tick::class)->run($this->valley);

        $this->assertSame(20, $house->fresh()->treasury);
        $this->assertSame(0, Law::count(), 'A House that cannot pay loses the Night Watch.');
    }

    public function test_the_season_ends_with_a_fresh_map_and_players_keep_a_fifth_of_their_corn(): void
    {
        $leader = $this->player(attributes: ['corn' => 500, 'rosettes' => 300]);
        $faction = $this->faction('Old Guard', $leader);
        $this->hold($faction, 'farmhouse', 'orchard');
        $faction->forceFill(['treasury' => 1000])->save();

        $this->travel(config('game.season_days') + 1)->days();
        app(Tick::class)->run($this->valley);

        $this->assertSame(2, Season::count());
        $this->assertSame(2, $this->season()->number);
        $this->assertNull($this->valley->house());
        $this->assertSame(100, $leader->fresh()->corn);
        $this->assertSame(300, $leader->fresh()->rosettes, 'Rosettes carry over.');
        $this->assertSame(100, $faction->fresh()->treasury);
    }

    public function test_the_daily_puzzle_changes_one_number_and_is_the_same_for_everyone(): void
    {
        $puzzle = app(Puzzle::class)->forDate('2026-10-20');

        $this->assertSame(4, $puzzle['number']);
        $this->assertSame($puzzle, app(Puzzle::class)->forDate('2026-10-20'));

        $changed = array_keys(array_diff_assoc($puzzle['before'], $puzzle['after']));
        $this->assertSame([$puzzle['answer']], $changed);
    }

    public function test_players_earn_rosettes_for_solving_the_puzzle_once_a_day(): void
    {
        $player = $this->player();
        $answer = app(Puzzle::class)->forDate(Clock::today())['answer'];

        $this->get(route('puzzle'))->assertInertia(fn (Assert $page) => $page->component('puzzle')->where('player', false));

        $this->actingAs($player->user)->post(route('puzzle.solve'), ['answer' => $answer, 'seconds' => 8])->assertRedirect();
        $this->actingAs($player->user)->post(route('puzzle.solve'), ['answer' => $answer, 'seconds' => 8]);

        $this->assertSame(15, $player->fresh()->rosettes);
    }

    public function test_the_public_pages_render(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Take the farm.', false)->assertSee('Not affiliated with or endorsed by the estate of George Orwell.');
        $this->get(route('privacy'))->assertOk()->assertSee('POPIA');
        $this->get(route('sitemap'))->assertOk()->assertSee('<loc>', false);
        $this->get(route('robots'))->assertOk()->assertSee('Sitemap:');
    }
}
