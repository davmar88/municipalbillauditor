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
use App\Game\LawTemplate;
use App\Game\Uprisings;
use App\Game\Wall;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Law;
use App\Models\Player;
use App\Models\UnrestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame($faction->id, $this->valley->fresh()->house()?->id);
        $this->assertSame(15, $rebels[0]->fresh()->rosettes);
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

    public function test_only_lone_wolves_from_level_three_commit_crimes(): void
    {
        $member = $this->player(level: 5);
        $member->forceFill(['faction_id' => $this->house->id])->save();

        $this->assertRefused(fn () => app(Crimes::class)->commit($member->fresh(), CrimeKind::Loot, $this->house), 'Only lone wolves');
        $this->assertRefused(fn () => app(Crimes::class)->commit($this->player(level: 2), CrimeKind::Loot, $this->house), 'level 3');
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

        $this->travelTo(Clock::now()->setTime(19, 5));
        app(GateNight::class)->resolveIfDue($this->season(), app(Wall::class));

        $this->assertSame(15, $defenders[0]->fresh()->rosettes);
        $this->assertSame(Clock::today(), $this->season()->fresh()->last_gate_night_on->toDateString());
    }

    public function test_when_the_gate_falls_the_humans_burn_two_outer_fields(): void
    {
        $this->travelTo(Clock::now()->next('Sunday')->setTime(19, 5));
        $this->hold($this->house, 'quarry', 'wildwood', 'old_well');

        app(GateNight::class)->resolveIfDue($this->season(), app(Wall::class));

        $this->assertSame(1, $this->house->regions()->where('ring', 'outer')->count());
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
