<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Dice;
use App\Game\GameException;
use App\Game\GateNight;
use App\Game\Homes;
use App\Game\LawTemplate;
use App\Game\RaidRules;
use App\Game\Uprisings;
use App\Game\Wall;
use App\Game\Warrants;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Law;
use App\Models\Notice;
use App\Models\Player;
use App\Models\UnrestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Uprisings against the House, the lone wolves' crimes, and Gate Night.
 */
class UprisingsAndCrimesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Player $ruler;

    private Faction $house;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
        // Noon may be earlier than the season started today: start it the day before, so what happens now counts this season.
        $this->season()->forceFill(['starts_at' => now()->subDay()])->save();
        $this->ruler = $this->player(Species::Dog);
        $this->house = $this->faction('The House', $this->ruler);
        $this->hold($this->house, 'farmhouse');
    }

    private function anger(int $amount): void
    {
        UnrestEvent::create(['season_id' => $this->season()->id, 'amount' => $amount, 'reason' => 'Test anger', 'expires_at' => now()->addDays(3)]);
    }

    public function test_an_uprising_needs_unrest_of_sixty(): void
    {
        $this->expectExceptionMessage('once unrest reaches 60');

        app(Uprisings::class)->call($this->player(), 1);
    }

    public function test_a_won_uprising_hands_the_farmhouse_to_the_faction_that_gave_most(): void
    {
        $this->anger(60);
        $rebels = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $faction = $this->faction('Rebels', ...$rebels);
        $uprisings = app(Uprisings::class);

        $uprising = $uprisings->call($rebels[0], 3);
        $this->assertSame('gathering', $uprising->status);
        $this->assertSame(3, $uprising->pledges_needed);

        $uprisings->pledge($rebels[1], $uprising, 'rebel', 3);
        $uprisings->pledge($rebels[2], $uprising, 'rebel', 3);
        $this->assertSame('open', $uprising->fresh()->status);

        $uprisings->pledge($this->ruler, $uprising->fresh(), 'loyal', 1);

        $this->travel(7)->hours();
        $uprisings->resolveDue($this->season(), app(Battles::class), app(Wall::class));

        $this->assertSame('won', $uprising->fresh()->status);
        $this->assertSame($faction->id, $this->region('farmhouse')->faction_id);
        $this->assertNull($this->valley->fresh()->house(), 'The rebels hold the Farmhouse, and rule once they build a Head Office.');
        $this->assertSame(15, $rebels[0]->fresh()->rosettes);
    }

    public function test_lone_wolves_who_win_leave_the_farmhouse_empty(): void
    {
        $this->anger(60);
        $rebels = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $uprisings = app(Uprisings::class);

        $uprising = $uprisings->call($rebels[0], 3);
        $uprisings->pledge($rebels[1], $uprising, 'rebel', 3);
        $uprisings->pledge($rebels[2], $uprising, 'rebel', 3);

        $this->travel(7)->hours();
        $uprisings->resolveDue($this->season(), app(Battles::class), app(Wall::class));

        $this->assertSame('won', $uprising->fresh()->status);
        $this->assertNull($this->valley->fresh()->house());
        $this->assertSame(['The House has fallen'], UnrestEvent::query()->where('amount', -30)->pluck('reason')->all());
    }

    public function test_the_wall_tells_house_members_they_can_only_stand_with_the_house(): void
    {
        $this->anger(60);
        app(Uprisings::class)->call($this->player(), 1);

        $this->actingAs($this->ruler->user)->get(route('play.wall'))->assertInertia(fn (Assert $page) => $page
            ->where('uprising.status', 'gathering')
            ->where('uprising.houseMember', true));
        $this->actingAs($this->player()->user)->get(route('play.wall'))->assertInertia(fn (Assert $page) => $page
            ->where('uprising.houseMember', false));
    }

    public function test_house_members_can_only_stand_with_the_house(): void
    {
        $this->anger(60);
        $uprising = app(Uprisings::class)->call($this->player(), 1);

        $this->expectExceptionMessage('Loyalists can answer once the uprising opens');
        app(Uprisings::class)->pledge($this->ruler, $uprising, 'loyal', 1);
    }

    public function test_an_uprising_without_enough_pledges_fizzles_out(): void
    {
        $this->anger(60);
        $uprising = app(Uprisings::class)->call($this->player(), 1);

        $this->travel(3)->hours();
        app(Uprisings::class)->resolveDue($this->season(), app(Battles::class), app(Wall::class));

        $this->assertSame('failed', $uprising->fresh()->status);
        $this->assertSame($this->house->id, $this->valley->house()?->id);
    }

    public function test_at_ninety_the_valley_rises_by_itself(): void
    {
        $this->anger(90);

        app(Uprisings::class)->riseIfFurious($this->season());

        $this->assertSame('open', Uprisings::active($this->season())?->status);
    }

    public function test_anyone_from_level_three_commits_crimes_faction_members_too(): void
    {
        $member = $this->player(level: 5);
        $member->forceFill(['faction_id' => $this->house->id])->save();

        // Even looting their own faction: its rules decide what that costs them.
        $this->assertSame($this->house->id, app(Crimes::class)->commit($member->fresh(), CrimeKind::Loot, $this->house)->target_faction_id);
        $this->assertRefused(fn () => app(Crimes::class)->commit($this->player(level: 2), CrimeKind::Loot, $this->house), 'level 3');
    }

    public function test_turning_on_your_own_faction_earns_a_strike_where_it_is_forbidden(): void
    {
        $leader = $this->player(level: 5);
        $faction = $this->faction('Hotheads', $leader, $this->player(), $this->player());
        $leader->forceFill(['faction_role' => 'leader'])->save();
        $burner = $this->player(level: 5);
        $mate = $this->player(level: 5);
        $burner->forceFill(['faction_id' => $faction->id, 'faction_role' => 'member'])->save();
        $mate->forceFill(['faction_id' => $faction->id, 'faction_role' => 'member'])->save();
        app(RaidRules::class)->set($leader->fresh(), ['mates'], [], false);
        $this->homeFor($mate);

        $crime = app(Crimes::class)->commit($burner->fresh(), CrimeKind::Burn, target: $mate->fresh());

        $this->assertSame($mate->id, $crime->target_player_id);
        $this->assertSame(1, RaidRules::strikes($faction->fresh())->get($burner->id));
        // A faction member who commits a crime can have a bounty put on them, like any lone wolf.
        $this->assertTrue(Warrants::suspects($this->season())->contains(fn (array $suspect) => $suspect['player']->id === $burner->id));
        $this->assertStringContainsString($mate->name, Notice::query()->where('player_id', $leader->id)->latest('id')->value('text'));
    }

    public function test_a_loot_that_works_steals_a_tenth_of_the_treasury(): void
    {
        $this->house->forceFill(['treasury' => 1000])->save();
        $wolf = $this->player(level: 3);

        $crime = app(Crimes::class)->commit($wolf, CrimeKind::Loot, $this->house);
        $crime->forceFill(['seed' => $this->seedWhere(fn (float $roll) => $roll > 0.95)])->save();

        $this->travel(31)->minutes();
        app(Crimes::class)->resolveDue($this->season());

        $this->assertFalse($crime->fresh()->caught);
        $this->assertSame(900, $this->house->fresh()->treasury);
        $this->assertSame(100 + 100, $wolf->fresh()->corn);
    }

    public function test_a_caught_criminal_goes_to_the_pound(): void
    {
        $wolf = $this->player(level: 3);
        $target = $this->player();
        $target->forceFill(['faction_id' => $this->house->id])->save();
        $this->homeFor($target);

        $crime = app(Crimes::class)->commit($wolf, CrimeKind::Burn, null, $target->fresh());
        $crime->forceFill(['seed' => $this->seedWhere(fn (float $roll) => $roll < 0.1)])->save();

        $this->travel(31)->minutes();
        app(Crimes::class)->resolveDue($this->season());

        $wolf->refresh();
        $this->assertTrue($wolf->inPound());
        $this->assertSame(1, $wolf->wanted);
        $this->assertFalse($target->fresh()->homeIsClosed());
    }

    public function test_a_burned_home_closes_its_shop_until_rebuilt(): void
    {
        $wolf = $this->player(level: 3);
        $target = $this->player(attributes: ['corn' => 150]);
        $target->forceFill(['faction_id' => $this->house->id])->save();
        $this->homeFor($target);

        $crime = app(Crimes::class)->commit($wolf, CrimeKind::Burn, null, $target->fresh());
        $crime->forceFill(['seed' => $this->seedWhere(fn (float $roll) => $roll > 0.95)])->save();
        $this->travel(31)->minutes();
        app(Crimes::class)->resolveDue($this->season());

        $this->assertTrue($target->fresh()->homeIsClosed());

        app(Crimes::class)->rebuild($target->fresh());
        $this->assertFalse($target->fresh()->homeIsClosed());
        $this->assertSame(50, $target->fresh()->corn);
    }

    public function test_dogs_and_the_night_watch_make_getting_caught_likelier(): void
    {
        $wolf = $this->player(level: 3);
        $crime = app(Crimes::class)->commit($wolf, CrimeKind::Loot, $this->house);
        $crimes = app(Crimes::class);

        $this->assertEqualsWithDelta(0.35, $crimes->catchChance($this->season(), $crime), 0.001, 'One dog in the House.');

        Law::create(['season_id' => $this->season()->id, 'slot' => 1, 'template' => LawTemplate::NightWatch]);
        $this->assertEqualsWithDelta(0.60, $crimes->catchChance($this->season(), $crime), 0.001);
    }

    public function test_one_crime_a_day(): void
    {
        $wolf = $this->player(level: 3);
        app(Crimes::class)->commit($wolf, CrimeKind::Loot, $this->house);

        $this->assertRefused(fn () => app(Crimes::class)->commit($wolf->fresh(), CrimeKind::Loot, $this->faction('Other', $this->player())), 'already tried a crime today');
        $this->assertSame(1, Crime::count());
    }

    public function test_gate_night_rewards_defenders_when_the_gate_holds(): void
    {
        $this->travelTo(Clock::now()->next('Sunday')->setTime(15, 0));
        $defenders = [];

        for ($i = 0; $i < 4; $i++) {
            $defenders[] = $defender = $this->player(Species::Dog);
            app(GateNight::class)->defend($defender, 3);
        }

        // The map shows how strong the gate is before the raid. Five animals
        // are active this week, so Ironfield sends ten men rather than six.
        $this->actingAs($this->ruler->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('gate.open', true)
            ->where('gate.defenders', 4)
            ->where('gate.strength', 15.6)
            ->where('gate.humans', 10)
            ->where('gate.result', null));

        $this->travelTo(Clock::now()->setTime(19, 5));
        app(GateNight::class)->resolveIfDue($this->season(), app(Wall::class));

        $this->assertSame(15, $defenders[0]->fresh()->rosettes);
        $this->assertSame(Clock::today(), $this->season()->fresh()->last_gate_night_on->toDateString());
        $this->assertSame("🛡 The gate held against Ironfield's 10 men at 19:00! You earned 15 Rosettes for defending it.", Notice::query()->where('player_id', $defenders[0]->id)->latest('id')->value('text'));
        $this->assertSame("🛡 The gate held against Ironfield's 10 men at 19:00, thanks to its 4 defenders.", Notice::query()->where('player_id', $this->ruler->id)->latest('id')->value('text'));

        // And how it went for the rest of the evening.
        $this->actingAs($this->ruler->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('gate.open', false)
            ->where('gate.result.held', true)
            ->where('gate.result.defenders', 4));
    }

    public function test_when_the_gate_falls_the_humans_burn_two_outer_fields(): void
    {
        $this->travelTo(Clock::now()->next('Sunday')->setTime(19, 5));
        $this->hold($this->house, 'quarry', 'wildwood', 'old_well');

        app(GateNight::class)->resolveIfDue($this->season(), app(Wall::class));

        $this->assertSame(1, $this->house->regions()->where('ring', 'outer')->count());
        $burned = $this->season()->fresh()->last_gate_night['burned'];
        $this->assertCount(2, $burned);
        $this->assertSame("🔥 Ironfield's 6 men broke through the gate at 19:00, with nobody defending it. They burned {$burned[0]} and {$burned[1]}.", Notice::query()->where('player_id', $this->ruler->id)->latest('id')->value('text'));
        $this->assertSame("Ironfield's men burned the outer fields", UnrestEvent::query()->latest('id')->value('reason'));
    }

    public function test_when_the_gate_falls_with_no_outer_field_held_nothing_burns(): void
    {
        $this->travelTo(Clock::now()->next('Sunday')->setTime(15, 0));
        $defender = $this->player(Species::Pig);
        app(GateNight::class)->defend($defender, 3);
        $this->travelTo(Clock::now()->setTime(19, 5));

        app(GateNight::class)->resolveIfDue($this->season(), app(Wall::class));

        // MySQL keeps a JSON object's keys in its own order, so check each one.
        $night = $this->season()->fresh()->last_gate_night;
        $this->assertFalse($night['held']);
        $this->assertSame(1, $night['defenders']);
        $this->assertSame([], $night['burned']);
        $this->assertSame("🔥 Ironfield's 6 men broke through the gate at 19:00. No field beyond the fences was held, so nothing burned. You earned 5 Rosettes for fighting.", Notice::query()->where('player_id', $defender->id)->latest('id')->value('text'));
        $this->assertSame("Ironfield's men broke through the gate", UnrestEvent::query()->latest('id')->value('reason'));
    }

    public function test_only_a_home_that_stands_can_burn(): void
    {
        $wolf = $this->player(level: 3);
        $homeless = $this->player();
        $homeless->forceFill(['faction_id' => $this->house->id])->save();

        $this->assertRefused(fn () => app(Crimes::class)->commit($wolf, CrimeKind::Burn, null, $homeless->fresh()), "There's nothing to burn");
    }

    public function test_putting_a_fire_out_saves_the_home_and_spots_the_burner(): void
    {
        $wolf = $this->player(level: 3);
        $target = $this->player();
        $target->forceFill(['faction_id' => $this->house->id])->save();
        $home = $this->homeFor($target);
        $crime = app(Crimes::class)->commit($wolf, CrimeKind::Burn, null, $target->fresh());
        $before = app(Crimes::class)->catchChance($this->season(), $crime);

        // The map shows it alight while it burns.
        $this->actingAs($target->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('homes', fn ($homes) => collect($homes)->firstWhere('id', $home->id)['fire'] === 'burning'));

        $this->assertRefused(fn () => app(Homes::class)->douse($wolf->fresh(), $home), 'You set this fire');
        $mate = $this->player(attributes: ['region_id' => $home->region_id, 'pos_x' => $home->x + 2, 'pos_y' => $home->y]);
        app(Homes::class)->douse($mate, $home);

        $crime->refresh();
        $this->assertSame($mate->id, $crime->doused_by);
        $this->assertEqualsWithDelta($before + Crimes::DOUSE_SPOTTED, app(Crimes::class)->catchChance($this->season(), $crime), 0.001);
        $this->assertRefused(fn () => app(Homes::class)->douse($mate->fresh(), $home), 'already out');

        $crime->forceFill(['seed' => $this->seedWhere(fn (float $roll) => $roll > 0.95)])->save();
        $this->travel(31)->minutes();
        app(Crimes::class)->resolveDue($this->season());

        $this->assertFalse($target->fresh()->homeIsClosed(), 'The home still stands.');
        $this->assertTrue(Notice::query()->where('player_id', $target->id)->where('text', "🪣 {$mate->name} put out a fire at your home before it burned down.")->exists());
    }

    public function test_a_burned_down_home_shows_as_ash_until_rebuilt(): void
    {
        $wolf = $this->player(level: 3);
        $target = $this->player(attributes: ['corn' => 150]);
        $target->forceFill(['faction_id' => $this->house->id])->save();
        $home = $this->homeFor($target);
        $crime = app(Crimes::class)->commit($wolf, CrimeKind::Burn, null, $target->fresh());
        $crime->forceFill(['seed' => $this->seedWhere(fn (float $roll) => $roll > 0.95)])->save();
        $this->travel(31)->minutes();
        app(Crimes::class)->resolveDue($this->season());

        $fire = fn () => collect($this->actingAs($target->user)->get(route('play'))->viewData('page')['props']['homes'])->firstWhere('id', $home->id)['fire'];
        $this->assertSame('ash', $fire());

        app(Crimes::class)->rebuild($target->fresh());
        $this->assertNull($fire());
    }

    private function seedWhere(callable $wanted): int
    {
        for ($seed = 1; $seed < 100000; $seed++) {
            if ($wanted(Dice::roll($seed, 'caught'))) {
                return $seed;
            }
        }

        $this->fail('No seed found.');
    }

    private function assertRefused(callable $attempt, string $because): void
    {
        try {
            $attempt();
            $this->fail("Expected a refusal ({$because}).");
        } catch (GameException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }
}
