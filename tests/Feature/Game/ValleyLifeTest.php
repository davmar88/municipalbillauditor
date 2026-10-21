<?php

namespace Tests\Feature\Game;

use App\Enums\Species;
use App\Game\Alliances;
use App\Game\Battles;
use App\Game\Finale;
use App\Game\GameException;
use App\Game\Homes;
use App\Game\Movement;
use App\Game\Pets;
use App\Game\Rivalry;
use App\Game\Rosettes;
use App\Game\Seasons;
use App\Game\ValleyEvents;
use App\Game\ValleyJobs;
use App\Models\Alliance;
use App\Models\HallOfFame;
use App\Models\Home;
use App\Models\Notice;
use App\Models\ValleyJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValleyLifeTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['game.valley_life.enabled' => true]);
        $this->makeValley();
    }

    public function test_the_season_finale_crowns_a_faction_and_three_animals(): void
    {
        $leader = $this->player();
        $member = $this->player(Species::Dog);
        $ours = $this->faction('Vlinderstadt', $leader, $member);
        $rival = $this->faction('Thistle Hollow', $this->player());
        $this->hold($ours, 'henhouse', 'thornbury');
        $this->hold($rival, 'granary');

        $star = $this->player(Species::Goat);
        Rosettes::award($star, 90, 'Test', 'test:star', $this->season());
        Rosettes::award($member, 40, 'Test', 'test:member', $this->season());

        // The final days pay double for battles.
        $this->assertSame(1, Finale::boost($this->season()));
        $this->travelTo($this->season()->ends_at->subDays(2));
        $this->assertSame(2, Finale::boost($this->season()));

        $this->actingAs($leader->user)->get(route('play.valley'))->assertInertia(fn ($page) => $page
            ->where('season.finalDays', true)
            ->where('podium.factions.0.name', 'Vlinderstadt')
            ->where('podium.animals.0.name', $star->name));

        $cornBefore = $member->fresh()->corn;
        app(Seasons::class)->end($this->valley, $this->season());

        $this->assertSame('Vlinderstadt', HallOfFame::query()->where('kind', 'season_faction')->value('name'));
        $this->assertSame([$star->name, $member->name], HallOfFame::query()->where('kind', 'season_animal')->orderBy('place')->limit(2)->pluck('name')->all());
        // Paid after the reset: a fifth of the Corn, then the prizes on top.
        $this->assertSame(intdiv($cornBefore, 5) + Finale::FACTION_PRIZE['corn'] + Finale::ANIMAL_PRIZES[2]['corn'], $member->fresh()->corn);

        $this->actingAs($leader->user)->get(route('play.valley'))->assertInertia(fn ($page) => $page
            ->where('hall.0.faction.name', 'Vlinderstadt')
            ->where('hall.0.animals.0.name', $star->name));
    }

    public function test_the_weekly_rivalry_scores_factions_and_names_a_winner(): void
    {
        $ours = $this->faction('Vlinderstadt', $this->player());
        $this->hold($ours, 'henhouse', 'thornbury');
        $this->faction('Thistle Hollow', $this->player());

        $board = Rivalry::board($this->valley);
        $this->assertSame('Vlinderstadt', $board[0]['name']);
        $this->assertSame(2 * Rivalry::LAND_POINTS, $board[0]['points']);

        $this->travel(8)->days();
        Rivalry::settleWeek($this->valley, 1);
        Rivalry::settleWeek($this->valley, 1);
        $this->assertSame(1, HallOfFame::query()->where('kind', 'week')->count());
        $this->assertSame('Vlinderstadt', Rivalry::lastWinner($this->valley->id)?->name);
    }

    public function test_allies_cannot_raid_each_other_may_defend_and_can_betray(): void
    {
        $a = [$this->player(), $this->player(), $this->player()];
        $b = [$this->player(Species::Dog), $this->player(Species::Dog), $this->player(Species::Dog)];
        $red = $this->faction('Red Guard', ...$a);
        $blue = $this->faction('Blue Guard', ...$b);
        $this->hold($blue, 'henhouse');
        $alliances = app(Alliances::class);

        // Only the leader or council make offers.
        try {
            $alliances->propose($a[1], $blue);
            $this->fail('Members cannot offer alliances.');
        } catch (GameException) {
        }

        $this->actingAs($a[0]->user)->post(route('play.alliance.propose', $blue))->assertRedirect();
        $offer = $alliances::page($b[0])['offers'][0];
        $this->actingAs($b[0]->user)->post(route('play.alliance.answer', $offer['id']), ['accept' => true]);
        $this->assertTrue(Alliances::allied($red->id, $blue->id));
        $this->assertSame($blue->id, Alliances::page($a[0])['ally']['id']);

        // No raiding an ally.
        $this->expectExceptionMessage('belongs to your ally');

        try {
            app(Battles::class)->launch($a[0], $this->region('henhouse'), 1);
        } finally {
            // Betrayal: the valley hears, and the land is fair game again.
            $this->actingAs($a[0]->user)->delete(route('play.alliance.break'));
            $this->assertFalse(Alliances::allied($red->id, $blue->id));
            $this->assertTrue(Notice::query()->where('text', 'like', '%broke its alliance%')->exists());
            app(Battles::class)->launch($a[0], $this->region('henhouse'), 1);
        }
    }

    public function test_an_ally_can_defend_and_live_on_allied_land(): void
    {
        $red = $this->faction('Red Guard', $this->player(), $this->player(), $this->player());
        $guard = $this->player(Species::Dog);
        $blue = $this->faction('Blue Guard', $guard);
        $green = $this->faction('Green Guard', $this->player());
        $this->hold($green, 'henhouse');
        app(Alliances::class)->propose($green->members()->first(), $blue);
        app(Alliances::class)->answer($guard, Alliance::query()->first(), true);

        $attack = app(Battles::class)->launch($red->members()->first(), $this->region('henhouse'), 1);
        $this->assertSame('defence', app(Battles::class)->commit($guard, $attack, 2)->side);

        // A home on the ally's land isn't moved off.
        $this->stand($guard, 'henhouse');
        $home = $this->homeFor($guard->fresh());
        $this->travel(4)->days();
        app(Homes::class)->checkLand($this->valley->id);
        $this->assertNull(Home::query()->find($home->id)?->foreign_since);
    }

    public function test_the_first_five_at_a_valley_event_win(): void
    {
        $events = app(ValleyEvents::class);
        $far = $this->player();
        $event = $events->start($this->valley, 'flood');
        $this->assertNotNull($event);
        $this->assertTrue(Notice::query()->where('text', 'like', '%Flood at%')->exists());

        $this->expectException(GameException::class);

        try {
            $events->join($far, $event);
        } finally {
            $winners = collect(range(1, 5))->map(function () use ($event) {
                $animal = $this->player();
                $this->stand($animal, $event->region->key);

                return $animal->fresh();
            });
            $cornBefore = $winners[0]->corn;

            foreach ($winners as $animal) {
                $this->actingAs($animal->user)->post(route('play.event.join', $event))->assertRedirect();
            }

            $this->assertSame($cornBefore + 50, $winners[0]->fresh()->corn);
            $this->assertSame(0, ValleyEvents::live($winners[0])[0]['left']);

            $late = $this->player();
            $this->stand($late, $event->region->key);
            $events->join($late->fresh(), $event);
        }
    }

    public function test_events_come_twice_a_day(): void
    {
        $this->travelTo(now()->setTimezone(config('game.timezone', 'Africa/Johannesburg'))->setTime(12, 30));
        $this->assertNotNull(app(ValleyEvents::class)->schedule($this->valley));
        $this->assertNull(app(ValleyEvents::class)->schedule($this->valley));
        $this->travelTo(now()->setTime(19, 10));
        $this->assertNotNull(app(ValleyEvents::class)->schedule($this->valley));
        $this->assertNull(app(ValleyEvents::class)->schedule($this->valley));
    }

    public function test_the_bounty_board_pays_the_first_to_do_a_job(): void
    {
        $jobs = app(ValleyJobs::class);
        $victimFaction = $this->faction('Thistle Hollow', $this->player());
        $jobs->post($this->valley);
        $this->assertSame(ValleyJobs::OPEN, ValleyJob::query()->whereNull('done_at')->count());

        $bring = $jobs->postOne($this->valley, 'bring');
        $runner = $this->player(Species::Goat, 1, ['materials' => [$bring->material => $bring->amount + 2]]);
        $corn = $runner->corn;
        $this->actingAs($runner->user)->post(route('play.job.bring', $bring))->assertRedirect();
        $this->assertSame(2, $runner->fresh()->material($bring->material));
        $this->assertSame($corn + $bring->reward_corn, $runner->fresh()->corn);
        $this->assertNotNull($bring->fresh()->done_at);

        $rob = $jobs->postOne($this->valley, 'rob');
        $rob->forceFill(['target_faction_id' => $victimFaction->id])->save();
        $robber = $this->player();
        ValleyJobs::did($robber, 'rob', $victimFaction->id);
        $this->assertSame(1, ValleyJob::query()->where('kind', 'rob')->where('done_by', $robber->id)->count());

        $this->actingAs($robber->user)->get(route('play.valley'))->assertOk();
    }

    public function test_a_fed_pet_helps_and_a_hungry_one_sulks(): void
    {
        $rider = $this->player(Species::Pig, 1, ['corn' => 1000]);
        $pace = Movement::pace($rider);

        $this->actingAs($rider->user)->post(route('play.pet.adopt'), ['kind' => 'horse', 'name' => 'Boxer'])->assertRedirect();
        $this->assertSame(500, $rider->fresh()->corn);
        $this->assertEqualsWithDelta($pace * 1.08, Movement::pace($rider->fresh()), 0.001);

        // Fed too soon: full.
        $this->expectException(GameException::class);

        try {
            app(Pets::class)->feed($rider->fresh());
        } finally {
            foreach (range(1, 3) as $i) {
                $this->travel(13)->hours();
                app(Pets::class)->feed($rider->fresh());
            }
            $this->assertSame(2, Pets::of($rider)->level);

            $this->travel(3)->days();
            $this->assertEqualsWithDelta($pace, Movement::pace($rider->fresh()), 0.001);
            $this->assertFalse(Pets::card($rider)['pet']['happy']);
        }
    }
}
