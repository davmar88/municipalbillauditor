<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Game\Battles;
use App\Game\Chat;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Factions;
use App\Game\GameException;
use App\Game\GateNight;
use App\Game\Gathering;
use App\Game\Geography;
use App\Game\Irongate;
use App\Game\Movement;
use App\Game\Notices;
use App\Game\Players;
use App\Game\Wall;
use App\Game\Warrants;
use App\Models\Crime;
use App\Models\Notice;
use App\Models\Player;
use App\Models\Warrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Irongate, the humans' town past Ironfield Gate: its districts, its people,
 * outlaws lying low there, and outcasts.
 */
class IrongateTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    /** A House with Apples, an animal it suspects, and a warrant out on it. */
    private function outlaw(Species $species = Species::Pig): array
    {
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $house->forceFill(['apples' => 500])->save();
        $this->hold($house, 'farmhouse');
        $suspect = $this->player($species, level: 3);
        $victims = $this->faction('Victims', $this->player());
        app(Crimes::class)->commit($suspect, CrimeKind::Loot, $victims);
        Crime::query()->update(['resolved_at' => now()]);
        $warrant = app(Warrants::class)->issue($ruler->fresh(), $suspect->fresh(), 150);

        return [$ruler->fresh(), $suspect->fresh(), $warrant];
    }

    public function test_irongate_has_three_districts_off_ironfield_gate(): void
    {
        $yards = $this->region('irongate_yards');

        foreach (['irongate_rookery', 'irongate_yards', 'irongate_garrison'] as $key) {
            $this->assertSame('city', $this->region($key)->ring);
            $this->assertNull($this->region($key)->produces);
        }

        $this->assertEqualsCanonicalizing(
            ['ironfield_gate', 'irongate_rookery', 'irongate_garrison'],
            $yards->neighbours()->pluck('key')->all(),
        );
        $this->assertSame(['irongate_yards'], $this->region('irongate_rookery')->neighbours()->pluck('key')->all());
        $this->assertContains('irongate_yards', $this->region('ironfield_gate')->neighbours()->pluck('key')->all());
    }

    public function test_animals_walk_into_each_district_but_not_past_the_town(): void
    {
        $player = $this->player();

        foreach (['irongate_rookery', 'irongate_yards', 'irongate_garrison'] as $key) {
            [$x, $y] = Geography::hub($key);
            app(Movement::class)->walkTo($player->fresh(), $x, $y);
            $this->travel(30)->minutes();
            $this->assertSame($key, Players::lock($player->fresh())->location()->key);
        }

        $this->expectExceptionMessage("There's nothing there to walk to");
        app(Movement::class)->walkTo($player->fresh(), 2200, 480);
    }

    public function test_nobody_takes_builds_or_gathers_in_irongate(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Red Hooves', $leader, $this->player(), $this->player());
        $faction->forceFill(['treasury' => 1000])->save();
        $this->hold($faction, 'ironfield_gate');
        $this->stand($leader, 'ironfield_gate');

        try {
            app(Battles::class)->launch($leader->fresh(), $this->region('irongate_yards'), 1);
            $this->fail('Irongate was raided.');
        } catch (GameException $e) {
            $this->assertStringContainsString("Irongate, the humans' town", $e->getMessage());
        }

        $this->stand($leader, 'irongate_yards');
        $this->expectException(GameException::class);
        app(Gathering::class)->gather($leader->fresh(), $this->region('irongate_yards'));
    }

    public function test_a_lost_gate_night_never_burns_irongate(): void
    {
        $holders = $this->faction('Holders', $this->player());
        $this->travelTo(Clock::now()->next('Sunday')->setTime(19, 5));
        $this->hold($holders, 'quarry', 'wildwood', 'sheepfold');

        app(GateNight::class)->resolveIfDue($this->season(), app(Wall::class));
        $this->assertCount(2, $this->season()->fresh()->last_gate_night['burned']);

        foreach (['irongate_rookery', 'irongate_yards', 'irongate_garrison'] as $key) {
            $this->assertNull($this->region($key)->faction_id);
        }
    }

    public function test_an_outlaw_lying_low_is_hidden_except_in_its_district_or_from_a_dog(): void
    {
        [$ruler, $suspect] = $this->outlaw();
        $this->stand($suspect, 'irongate_rookery');
        $dog = $this->player(Species::Dog);
        $neighbour = $this->player();

        $rookery = fn (Assert $page) => collect($page->toArray()['props']['regions'])->firstWhere('key', 'irongate_rookery');

        // From the farm, or another district, the Rookery looks empty.
        $this->stand($ruler, 'farmhouse');
        $this->actingAs($ruler->user)->get(route('play'))->assertInertia(fn (Assert $page) => $this->assertSame(0, $rookery($page)['animals']['count']));
        $this->stand($neighbour, 'irongate_yards');
        $this->actingAs($neighbour->user)->get(route('play'))->assertInertia(fn (Assert $page) => $this->assertSame(0, $rookery($page)['animals']['count']));

        // In the same district, or a Dog anywhere in the town, sees it.
        $this->stand($neighbour, 'irongate_rookery');
        // A fresh login, as a new page load would have.
        $this->actingAs($neighbour->fresh()->user)->get(route('play'))->assertInertia(fn (Assert $page) => $this->assertSame($suspect->id, collect($rookery($page)['animals']['sample'])->firstWhere('me', false)['id']));
        $this->stand($dog, 'irongate_garrison');
        $this->actingAs($dog->fresh()->user)->get(route('play'))->assertInertia(fn (Assert $page) => $this->assertSame(2, $rookery($page)['animals']['count']));

        // The Wanted board knows only the town.
        $this->actingAs($ruler->user)->get(route('play.wanted'))->assertInertia(fn (Assert $page) => $page->where('warrants.0.lastSeen', 'Irongate'));
    }

    public function test_hunting_in_irongate_means_finding_the_district_or_paying_wick(): void
    {
        [, $suspect, $warrant] = $this->outlaw();
        $this->stand($suspect, 'irongate_garrison');
        $hunter = $this->player(attributes: ['corn' => 100, 'horseshoes' => 10]);

        // Asking from the wrong district gives nothing away, and costs nothing.
        $this->stand($hunter, 'irongate_rookery');

        try {
            app(Warrants::class)->arrest($hunter->fresh(), $warrant, 0.0);
            $this->fail('Arrested from the wrong district.');
        } catch (GameException $e) {
            $this->assertStringContainsString('somewhere in Irongate', $e->getMessage());
            $this->assertStringNotContainsString('Garrison', $e->getMessage());
        }
        $this->assertSame(10, $hunter->fresh()->horseshoes);

        // Wick tells, for Corn, and the outlaw hears someone asked.
        $this->actingAs($hunter->user)->post(route('play.irongate.tip', $warrant))->assertRedirect();
        $this->assertSame(100 - Irongate::TIP_CORN, $hunter->fresh()->corn);
        $this->assertTrue(Notice::query()->where('player_id', $suspect->id)->where('text', 'like', '%paid Wick%')->exists());

        // Once per half hour per outlaw.
        $this->expectExceptionMessage('not long ago');
        app(Irongate::class)->tip($hunter->fresh(), $warrant);
    }

    public function test_a_tip_needs_the_outlaw_in_irongate_and_the_hunter_too(): void
    {
        [, $suspect, $warrant] = $this->outlaw();
        $hunter = $this->player(attributes: ['corn' => 100]);
        $this->stand($suspect, 'quarry');
        $this->stand($hunter, 'irongate_yards');

        try {
            app(Irongate::class)->tip($hunter->fresh(), $warrant);
            $this->fail('Tipped about an outlaw outside Irongate.');
        } catch (GameException $e) {
            $this->assertStringContainsString("isn't hiding in Irongate", $e->getMessage());
        }

        $this->stand($suspect, 'irongate_rookery');
        $this->stand($hunter, 'quarry');
        $this->expectExceptionMessage('Wick only talks in Irongate');
        app(Irongate::class)->tip($hunter->fresh(), $warrant);
        $this->assertSame(100, $hunter->fresh()->corn);
    }

    public function test_the_fence_buys_from_lone_wolves_in_the_yards_up_to_a_daily_cap(): void
    {
        $wolf = $this->player(attributes: ['corn' => 0, 'materials' => ['stone' => 40, 'honey' => 10]]);
        $this->stand($wolf, 'irongate_yards');

        $this->actingAs($wolf->user)->post(route('play.irongate.fence'), ['material' => 'honey', 'amount' => 4])->assertRedirect();
        $this->assertSame(4 * Irongate::FENCE_RARE_PRICE, $wolf->fresh()->corn);
        $this->assertSame(6, $wolf->fresh()->materials['honey']);

        // She stops at the day's cap, and sells only what you have.
        app(Irongate::class)->fence($wolf->fresh(), 'stone', 40);
        $this->assertSame(Irongate::FENCE_DAILY_CORN, $wolf->fresh()->corn);
        $this->assertSame(0, Irongate::fenceLeft($wolf->fresh()));

        $this->expectExceptionMessage('all she will today');
        app(Irongate::class)->fence($wolf->fresh(), 'stone', 1);
    }

    public function test_the_fence_turns_away_faction_animals_and_anyone_outside_the_yards(): void
    {
        $member = $this->player(attributes: ['materials' => ['stone' => 10]]);
        $this->faction('Red Hooves', $member);
        $this->stand($member, 'irongate_yards');
        $wolf = $this->player(attributes: ['materials' => ['stone' => 10]]);
        $this->stand($wolf, 'irongate_rookery');

        foreach ([$member, $wolf] as $seller) {
            try {
                app(Irongate::class)->fence($seller->fresh(), 'stone', 5);
                $this->fail("{$seller->name} sold to the fence.");
            } catch (GameException) {
                $this->assertSame(10, $seller->fresh()->materials['stone']);
            }
        }
    }

    public function test_the_townsfolk_are_not_players(): void
    {
        $this->player();
        $before = Players::activeCount($this->valley);

        $this->assertSame($before, Player::query()->count());
        $this->assertCount(Player::query()->count(), Notices::valley($this->valley->id));
        $this->actingAs(Player::query()->first()->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->has('irongate.folk', count(Irongate::FOLK))
            ->where('irongate.muster', GateNight::humans($this->valley))
            ->where('irongate.tip', Irongate::TIP_CORN));
    }

    public function test_an_outcast_hears_it_and_waits_a_day_before_crime(): void
    {
        $leader = $this->player();
        $member = $this->player(level: 3);
        $faction = $this->faction('Red Hooves', $leader, $member);
        $victims = $this->faction('Victims', $this->player());

        app(Factions::class)->remove($leader->fresh(), $member->fresh());

        $this->assertTrue(Notice::query()->where('player_id', $member->id)->where('text', 'like', '%threw you out of Red Hooves%')->exists());
        $this->assertNotNull($member->fresh()->left_faction_at);

        try {
            app(Crimes::class)->commit($member->fresh(), CrimeKind::Loot, $victims);
            $this->fail('Committed a crime straight after leaving.');
        } catch (GameException $e) {
            $this->assertStringContainsString('only just left your faction', $e->getMessage());
        }

        $this->travel(25)->hours();
        app(Crimes::class)->commit($member->fresh(), CrimeKind::Loot, $victims);

        // Until it plays out, no faction will have it, not even one of its own.
        try {
            app(Factions::class)->found($member->fresh()->forceFill(['corn' => 1000]), 'Outcasts', '#b39aff', null);
            $this->fail('Founded a faction with a crime playing out.');
        } catch (GameException $e) {
            $this->assertStringContainsString('crime still playing out', $e->getMessage());
        }

        $this->expectExceptionMessage('crime still playing out');
        app(Factions::class)->join($member->fresh(), $faction->fresh());
    }

    public function test_lone_wolves_read_the_rookery_board(): void
    {
        $wolf = $this->player();
        $member = $this->player();
        $this->faction('Red Hooves', $member);
        $chat = app(Chat::class);

        $this->assertContains(Chat::rookery(), collect($chat->channels($wolf->fresh()))->pluck('key')->all());
        $this->assertTrue($chat->canRead($wolf->fresh(), Chat::rookery()));
        $this->assertFalse($chat->canRead($member->fresh(), Chat::rookery()));
        $chat->post($wolf->fresh(), Chat::rookery(), 'Anyone seen the dog?');
        $this->assertCount(1, $chat->messages($wolf->fresh(), Chat::rookery()));
    }
}
