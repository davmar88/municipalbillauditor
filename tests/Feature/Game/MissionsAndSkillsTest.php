<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Chat;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\Gathering;
use App\Game\Horseshoes;
use App\Game\Market;
use App\Game\Missions;
use App\Game\Movement;
use App\Game\Skills;
use App\Models\Player;
use App\Models\PlayerMission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Missions grow a player; Market and Creativity rank them.
 */
class MissionsAndSkillsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    private function storyStep(Player $player): PlayerMission
    {
        Missions::ensure($player);

        return PlayerMission::query()->where('player_id', $player->id)->where('period', 'story')->whereNull('claimed_at')->firstOrFail();
    }

    public function test_the_story_starts_at_the_paddock_gate_and_opens_one_step_at_a_time(): void
    {
        $player = $this->player();
        $step = $this->storyStep($player);
        $this->assertSame('arrival.first_steps', $step->key);

        app(Movement::class)->walk($player, $this->region('market_square'));
        $step->refresh();
        $this->assertNotNull($step->completed_at);

        $corn = $player->fresh()->corn;
        Missions::claim($player->fresh(), $step);

        $this->assertSame($corn + 20, $player->fresh()->corn);
        $this->assertSame(5, $player->fresh()->rosettes);
        $this->assertSame('arrival.first_harvest', $this->storyStep($player)->key);
    }

    public function test_joining_a_faction_early_still_finishes_the_pick_a_side_step(): void
    {
        $player = $this->player();
        $this->faction('Early Birds', $this->player(), $player);

        foreach (['walk', 'gather', 'gather', 'gather', 'chat'] as $event) {
            Missions::record($player->fresh(), $event);

            $step = $this->storyStep($player);

            if ($step->completed_at !== null) {
                Missions::claim($player->fresh(), $step);
            }
        }

        $step = $this->storyStep($player);
        $this->assertSame('herd.belong', $step->key);
        $this->assertNotNull($step->completed_at, 'Already in a faction, so the step opens finished.');

        Missions::claim($player->fresh(), $step);
        $this->assertSame('herd.pull_weight', $this->storyStep($player)->key);
    }

    public function test_a_mission_pays_once_and_only_when_finished(): void
    {
        $player = $this->player();
        $step = $this->storyStep($player);

        try {
            Missions::claim($player, $step);
            $this->fail('An unfinished mission paid out.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Finish the mission first', $e->getMessage());
        }

        Missions::record($player, 'walk');
        Missions::claim($player->fresh(), $step);

        $this->expectExceptionMessage('already collected');
        Missions::claim($player->fresh(), $step->fresh());
    }

    public function test_another_players_mission_cant_be_claimed(): void
    {
        $player = $this->player();
        $other = $this->player();
        $step = $this->storyStep($player);
        Missions::record($player, 'walk');

        $this->expectExceptionMessage("isn't one of your missions");
        Missions::claim($other, $step);
    }

    public function test_gathering_counts_towards_a_step_that_asks_for_three(): void
    {
        $player = $this->player();
        $first = $this->storyStep($player);
        Missions::record($player, 'walk');
        Missions::claim($player->fresh(), $first);

        $this->stand($player, 'hayfield');

        foreach (range(1, 3) as $ignored) {
            app(Gathering::class)->gather($player->fresh(), $this->region('hayfield'));
        }

        $step = $this->storyStep($player);
        $this->assertSame('arrival.first_harvest', $step->key);
        $this->assertSame(3, $step->progress);
        $this->assertNotNull($step->completed_at);
    }

    public function test_horseshoe_rewards_never_overfill(): void
    {
        $player = $this->player();
        $first = $this->storyStep($player);
        Missions::record($player, 'walk');
        Missions::claim($player->fresh(), $first);

        $harvest = $this->storyStep($player);
        $harvest->forceFill(['progress' => 3, 'completed_at' => now()])->save();
        Missions::claim($player->fresh(), $harvest);

        $this->assertSame(Horseshoes::max(), Horseshoes::current($player->fresh()));
    }

    public function test_steps_above_your_level_wait_until_you_get_there(): void
    {
        $player = $this->player();
        PlayerMission::create(['player_id' => $player->id, 'key' => 'living.shop', 'period' => 'story', 'target' => 1]);

        Missions::record($player, 'list');

        $mission = PlayerMission::query()->where('key', 'living.shop')->first();
        $this->assertSame(0, $mission->progress);
    }

    public function test_each_animal_gets_three_jobs_a_day_and_one_suits_its_species(): void
    {
        $horse = $this->player(Species::Horse);
        $this->faction('Builders', $horse);
        $jobs = Missions::pick($horse->fresh(), Clock::today());

        $this->assertCount(Missions::DAILY_JOBS, $jobs);
        $this->assertSame($jobs, Missions::pick($horse->fresh(), Clock::today()), 'The same jobs all day.');
        $this->assertContains($jobs[0], Missions::favourites(Species::Horse));
    }

    public function test_jobs_fit_the_animal(): void
    {
        $loner = $this->player(Species::Dog, level: 3);
        $this->assertNotContains('daily.give', Missions::pick($loner, Clock::today()));
        $this->assertNotContains('daily.battle', Missions::pick($loner, Clock::today()));

        $newcomer = $this->player(Species::Pig);
        $this->assertNotContains('daily.sell', Missions::pick($newcomer, Clock::today()));
        $this->assertNotContains('daily.crime', Missions::pick($newcomer, Clock::today()));
    }

    public function test_yesterdays_jobs_cant_be_collected(): void
    {
        $player = $this->player();
        Missions::ensure($player);
        $job = PlayerMission::query()->where('player_id', $player->id)->where('period', Clock::today())->first();
        $job->forceFill(['progress' => $job->target, 'completed_at' => now()])->save();

        Carbon::setTestNow(now()->addDay());

        $this->expectExceptionMessage("Yesterday's jobs");
        Missions::claim($player, $job);
    }

    public function test_chat_counts_for_the_hello_step(): void
    {
        $player = $this->player();
        PlayerMission::create(['player_id' => $player->id, 'key' => 'arrival.hello', 'period' => 'story', 'target' => 1]);

        app(Chat::class)->post($player, Chat::valley(), 'Hello, Hollowmere!');

        $this->assertNotNull(PlayerMission::query()->where('key', 'arrival.hello')->first()->completed_at);
    }

    public function test_sales_grow_the_sellers_market_skill_and_the_makers_creativity(): void
    {
        $market = app(Market::class);
        $maker = $this->player(Species::Sheep, level: 2, attributes: ['materials' => ['straw' => 20, 'wool' => 20, 'wood' => 20, 'clay' => 5, 'dye' => 5, 'stone' => 0, 'eggs' => 0]]);
        $buyer = $this->player(Species::Pig, level: 2, attributes: ['corn' => 1000]);

        $design = $market->design($maker, ItemTemplate::Scarf, 'Violet Scarf', ['#b39aff', '#41d1ff'], 'stripes', null);
        $market->moderate($design, true);
        $this->assertSame(10, $maker->fresh()->creativity_skill, 'An approved design counts.');

        $item = $market->craft($maker->fresh(), $design->fresh());
        $listing = $market->list($maker->fresh(), $item, 100);
        $market->buy($buyer->fresh(), $listing);

        $this->assertSame(Market::marketPoints(100), $maker->fresh()->market_skill);
        $this->assertSame(1, $buyer->fresh()->market_skill);
        $this->assertSame(10 + 1 + 2 + intdiv(100, 50), $maker->fresh()->creativity_skill);
    }

    public function test_skill_points_are_never_paid_twice(): void
    {
        $player = $this->player();

        $this->assertTrue(Skills::award($player->id, Skills::MARKET, 5, 'Test', 'test:1'));
        $this->assertFalse(Skills::award($player->id, Skills::MARKET, 5, 'Test', 'test:1'));
        $this->assertSame(5, $player->fresh()->market_skill);
    }

    public function test_skill_levels_and_titles(): void
    {
        $this->assertSame(1, Skills::level(0));
        $this->assertSame(2, Skills::level(30));
        $this->assertSame(3, Skills::level(90));
        $this->assertSame('Stall hand', Skills::title(Skills::MARKET, 1));
        $this->assertSame('Trader', Skills::title(Skills::MARKET, 4));
        $this->assertSame('Designer', Skills::title(Skills::CREATIVITY, 7));
    }

    public function test_the_today_screen_shows_the_story_jobs_skills_and_shop(): void
    {
        $player = $this->player();

        $this->actingAs($player->user)->get(route('play.today'))->assertInertia(fn (Assert $page) => $page
            ->component('game/today')
            ->where('missions.story.key', 'arrival.first_steps')
            ->has('missions.daily', Missions::DAILY_JOBS)
            ->has('missions.chapters', 6)
            ->has('skills', 2)
            ->where('shop.shelves', 0)
            ->where('missionsWaiting', 0));
    }

    public function test_claiming_from_the_today_screen(): void
    {
        $player = $this->player();
        $step = $this->storyStep($player);
        Missions::record($player, 'walk');

        $this->actingAs($player->user)->get(route('play.today'))->assertInertia(fn (Assert $page) => $page->where('missionsWaiting', 1));
        $this->actingAs($player->user)->post(route('play.mission.claim', $step))->assertRedirect();

        $this->assertNotNull($step->fresh()->claimed_at);
    }

    public function test_rankings_list_the_best_traders_this_season_and_of_all_time(): void
    {
        $season = $this->season();
        $first = $this->player();
        $second = $this->player();
        $me = $this->player();

        Skills::award($first->id, Skills::MARKET, 50, 'Sales', 'a', $season);
        Skills::award($second->id, Skills::MARKET, 20, 'Sales', 'b', $season);
        Skills::award($second->id, Skills::MARKET, 100, 'Old sales', 'c');

        $this->actingAs($me->user)->get(route('play.rankings', ['board' => 'market']))->assertInertia(fn (Assert $page) => $page
            ->component('game/rankings')
            ->where('scope', 'season')
            ->has('rows', 2)
            ->where('rows.0.name', $first->name)
            ->where('rows.0.points', 50)
            ->where('me', null));

        $this->actingAs($me->user)->get(route('play.rankings', ['board' => 'market', 'scope' => 'all']))->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.name', $second->name)
            ->where('rows.0.points', 120));
    }
}
