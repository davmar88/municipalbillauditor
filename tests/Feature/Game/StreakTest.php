<?php

namespace Tests\Feature\Game;

use App\Game\Clock;
use App\Game\Horseshoes;
use App\Game\Streaks;
use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreakTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['game.streak.enabled' => true]);
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    public function test_coming_back_day_after_day_pays_more_and_missing_a_day_starts_over(): void
    {
        $player = $this->player(attributes: ['corn' => 0, 'horseshoes' => 0, 'horseshoes_at' => now()]);
        $streaks = app(Streaks::class);

        $this->assertSame(1, $streaks->count($player));
        $this->assertSame(20, $player->fresh()->corn);
        $this->assertNull($streaks->count($player->fresh()), 'Once a day.');
        $this->assertTrue(Notice::query()->where('player_id', $player->id)->where('text', 'like', '%Come back tomorrow for 40 Corn%')->exists());

        $this->travel(1)->days();
        $this->assertSame(2, $streaks->count($player->fresh()));
        $this->assertSame(60, $player->fresh()->corn);

        $this->travel(1)->days();
        $before = Horseshoes::current($player->fresh());
        $this->assertSame(3, $streaks->count($player->fresh()));
        $this->assertSame($before + 5, Horseshoes::current($player->fresh()));

        // A day missed: back to day one.
        $this->travel(2)->days();
        $this->assertSame(1, $streaks->count($player->fresh()));
        $this->assertSame(80, $player->fresh()->corn);
    }

    public function test_the_first_visit_of_the_day_counts_the_streak(): void
    {
        $player = $this->player(attributes: ['corn' => 0]);

        $this->actingAs($player->user)->get(route('play.today'))->assertOk();

        $this->assertSame(1, $player->fresh()->streak_days);
        $this->assertSame(20, $player->fresh()->corn);
    }
}
