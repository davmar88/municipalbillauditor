<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\FactionRole;
use App\Game\Apples;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Dice;
use App\Game\GameException;
use App\Game\Horseshoes;
use App\Game\Seasons;
use App\Game\Stock;
use App\Game\Warrants;
use App\Models\AppleEntry;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Notice;
use App\Models\Warrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Outlaws: plundering farm sections, the House's warrants, and the bounty
 * hunters who serve them.
 */
class OutlawsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Crimes $crimes;

    private Warrants $warrants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->crimes = app(Crimes::class);
        $this->warrants = app(Warrants::class);
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    /** A faction holding Thornbury Farm, and a lone wolf standing on it. */
    private function farmAndWolf(): array
    {
        $keeper = $this->player();
        $keepers = $this->faction('Beekeepers', $keeper);
        $keepers->forceFill(['treasury' => 500])->save();
        $this->hold($keepers, 'thornbury');
        $wolf = $this->player(level: 3);
        $this->stand($wolf, 'thornbury');

        return [$keepers->fresh(), $keeper, $wolf->fresh()];
    }

    private function getAway(Crime $crime): void
    {
        $chance = $this->crimes->catchChance($this->season(), $crime);

        foreach (range(1, 1000) as $seed) {
            if (Dice::roll($seed, 'caught') >= $chance) {
                $crime->forceFill(['seed' => $seed])->save();

                return;
            }
        }
    }

    public function test_a_caught_outlaw_pays_back_what_its_crimes_took(): void
    {
        [, $ruler] = $this->houseAndSuspect();
        $burner = $this->player(level: 3, attributes: ['corn' => 500]);
        $victim = $this->player(attributes: ['corn' => 0]);
        $this->faction('Burned', $victim);
        $this->homeFor($victim);
        $crime = $this->crimes->commit($burner, CrimeKind::Burn, null, $victim->fresh());
        $this->getAway($crime);
        $this->travel(31)->minutes();
        $this->crimes->resolveDue($this->season());
        $this->assertTrue($victim->fresh()->homeIsClosed());

        $warrant = $this->warrants->issue($ruler->fresh(), $burner->fresh(), 20);
        $ruler->forceFill(['region_id' => $burner->fresh()->region_id])->save();
        $this->assertTrue($this->warrants->arrest($ruler->fresh(), $warrant, 0.0));

        $this->assertSame(Crimes::REBUILD_CORN, $victim->fresh()->corn, 'The victim is paid what rebuilding costs.');
        $this->assertSame(500 - Crimes::REBUILD_CORN, $burner->fresh()->corn);
        $this->assertNotNull($crime->fresh()->repaid_at);
    }

    /** A House, and a lone wolf it can put a warrant on. */
    private function houseAndSuspect(): array
    {
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $house->forceFill(['apples' => 500])->save();
        $this->hold($house, 'farmhouse');
        $suspect = $this->player(level: 3);
        $victims = $this->faction('Victims', $this->player());
        $this->crimes->commit($suspect, CrimeKind::Loot, $victims);

        return [$house->fresh(), $ruler->fresh(), $suspect->fresh()];
    }

    public function test_a_lone_wolf_plunders_the_farm_section_they_stand_on(): void
    {
        [$keepers, $keeper, $wolf] = $this->farmAndWolf();

        $this->actingAs($wolf->user)->post(route('play.crime'), ['kind' => 'plunder'])->assertRedirect();

        $crime = Crime::sole();
        $this->assertSame(CrimeKind::Plunder, $crime->kind);
        $this->assertSame($this->region('thornbury')->id, $crime->region_id);
        $this->getAway($crime);
        $this->travel(31)->minutes();
        $this->crimes->resolveDue($this->season());

        // Everything the field held went into the wolf's bag, and the field is bare.
        $this->assertSame(Stock::rule($this->region('thornbury'))['max'], $wolf->fresh()->material('honey'));
        $this->assertSame(0, Stock::state($this->region('thornbury'))['left']);
        $this->assertStringContainsString('stripped Thornbury Farm bare', Notice::query()->where('player_id', $wolf->id)->latest('id')->value('text'));
        $this->assertSame(100 + Crimes::PLUNDER_CORN, $wolf->fresh()->corn);
        $this->assertSame(500 - Crimes::PLUNDER_CORN, $keepers->fresh()->treasury);
        $this->assertStringContainsString('plundered Thornbury Farm', Notice::query()->where('player_id', $keeper->id)->value('text'));
    }

    public function test_plunder_needs_a_held_farm_section_underfoot_and_waits_between_raids(): void
    {
        [, , $wolf] = $this->farmAndWolf();
        $this->stand($wolf, 'paddock');

        try {
            $this->crimes->commit($wolf->fresh(), CrimeKind::Plunder);
            $this->fail('The Paddock is no farm.');
        } catch (GameException $e) {
            $this->assertStringContainsString('farms beyond the fences', $e->getMessage());
        }

        $this->stand($wolf, 'thornbury');
        $this->crimes->commit($wolf->fresh(), CrimeKind::Plunder);

        try {
            $this->crimes->commit($wolf->fresh(), CrimeKind::Plunder);
            $this->fail('Lie low for an hour.');
        } catch (GameException $e) {
            $this->assertStringContainsString('plundered not long ago', $e->getMessage());
        }

        $other = $this->player(level: 3);
        $this->stand($other, 'thornbury');
        $this->expectExceptionMessage('its holders are watching');
        $this->crimes->commit($other->fresh(), CrimeKind::Plunder);
    }

    public function test_plunder_does_not_use_up_the_days_crime(): void
    {
        [$keepers, , $wolf] = $this->farmAndWolf();
        $this->crimes->commit($wolf, CrimeKind::Plunder);

        $crime = $this->crimes->commit($wolf->fresh(), CrimeKind::Loot, $this->faction('Elsewhere', $this->player()));

        $this->assertSame(CrimeKind::Loot, $crime->kind);
        $this->assertNotNull($keepers);
    }

    public function test_the_house_puts_a_warrant_on_a_suspect_with_a_bounty_from_its_store_of_apples(): void
    {
        [$house, $ruler, $suspect] = $this->houseAndSuspect();
        $bystander = $this->player();

        $this->actingAs($ruler->user)->post(route('play.warrant.issue'), ['player_id' => $suspect->id, 'bounty' => 200])->assertRedirect();

        $warrant = Warrant::sole();
        $this->assertSame(200, $warrant->bounty);
        $this->assertSame(300, $house->fresh()->apples, "The bounty comes out of the House's store of Apples.");
        $this->assertStringContainsString('with a bounty of 200 Apples', Notice::query()->where('player_id', $suspect->id)->latest('id')->value('text'));
        $this->assertSame("🎯 Wanted: {$suspect->name}, with a bounty of 200 Apples from The House.", Notice::query()->where('player_id', $bystander->id)->value('text'));

        $this->expectExceptionMessage('already a warrant');
        $this->warrants->issue($ruler->fresh(), $suspect, 100);
    }

    public function test_a_bounty_needs_the_apples_in_the_house_store(): void
    {
        [$house, $ruler, $suspect] = $this->houseAndSuspect();
        $house->forceFill(['apples' => 30])->save();

        try {
            $this->warrants->issue($ruler, $suspect, 40);
            $this->fail('The store holds 30 Apples.');
        } catch (GameException $e) {
            $this->assertStringContainsString("The House's store holds 30 Apples", $e->getMessage());
        }

        $this->assertSame(30, $house->fresh()->apples);
        $this->assertSame(0, Warrant::count());
    }

    public function test_members_give_apples_to_the_house_store_and_anyone_adds_their_own(): void
    {
        [$house, $ruler, $suspect] = $this->houseAndSuspect();
        $member = $this->player();
        $member->forceFill(['faction_id' => $house->id, 'faction_role' => FactionRole::Member, 'faction_joined_at' => now()->subDays(3)])->save();
        Apples::award($member, 25, 'Test', 'test:member');

        $this->actingAs($member->user)->post(route('play.give'), ['what' => 'apples', 'amount' => 20])->assertRedirect();

        $this->assertSame(5, $member->fresh()->apples);
        $this->assertSame(520, $house->fresh()->apples);
        $this->assertSame(5, (int) AppleEntry::query()->where('player_id', $member->id)->sum('amount'));

        $warrant = $this->warrants->issue($ruler, $suspect, 100);
        $this->expectExceptionMessage('You have 5 Apples');
        $this->warrants->raise($member->fresh(), $warrant, 6);
    }

    public function test_any_faction_leader_warrants_but_only_suspects(): void
    {
        [, $ruler, $suspect] = $this->houseAndSuspect();
        $outsider = $this->player();
        $rival = $this->faction('Outsiders', $outsider, $this->player());
        $rival->forceFill(['apples' => 50])->save();

        // Not just the House: any faction's leader puts a bounty up from its own store.
        $warrant = $this->warrants->issue($outsider->fresh(), $suspect, 40);
        $this->assertSame($rival->id, $warrant->faction_id);
        $this->assertSame(10, $rival->fresh()->apples);

        try {
            $this->warrants->issue($this->player(), $this->player(), 10);
            $this->fail('Only a leader or council.');
        } catch (GameException $e) {
            $this->assertStringContainsString("Only a faction's leader and council", $e->getMessage());
        }

        $this->expectExceptionMessage('done nothing the informants');
        $this->warrants->issue($ruler, $this->player(), 100);
    }

    public function test_a_bounty_hunter_catches_the_outlaw_where_they_stand(): void
    {
        [, $ruler, $suspect] = $this->houseAndSuspect();
        $warrant = $this->warrants->issue($ruler, $suspect, 200);
        $helper = $this->player();
        Apples::award($helper, 60, 'Test', 'test:helper');
        $this->warrants->raise($helper->fresh(), $warrant, 50);
        $this->assertSame(10, $helper->fresh()->apples);
        $hunter = $this->player();
        $this->stand($suspect, 'granary');

        try {
            $this->warrants->arrest($hunter, $warrant->fresh(), 0.0);
            $this->fail('The hunter is in the Paddock.');
        } catch (GameException $e) {
            $this->assertStringContainsString('is in Granary', $e->getMessage());
        }

        $this->stand($hunter, 'granary');
        $caught = $this->warrants->arrest($hunter->fresh(), $warrant->fresh(), 0.0);

        $this->assertTrue($caught);
        $this->assertTrue($suspect->fresh()->inPound());
        $this->assertSame(200, $hunter->fresh()->apples, 'The hunter collects the bounty less the game\'s 20%.');
        $this->assertSame(200, (int) AppleEntry::query()->where('player_id', $hunter->id)->sum('amount'));
        $this->assertSame(8, Horseshoes::current($hunter->fresh()));
        $this->assertSame('served', $warrant->fresh()->status);
        $this->assertSame($hunter->id, $warrant->fresh()->hunter_id);
    }

    public function test_an_outlaw_who_slips_away_is_out_of_reach_for_half_an_hour(): void
    {
        [, $ruler, $suspect] = $this->houseAndSuspect();
        $warrant = $this->warrants->issue($ruler, $suspect, 100);
        $hunter = $this->player();

        $this->assertFalse($this->warrants->arrest($hunter, $warrant, 0.99));
        $this->assertFalse($suspect->fresh()->inPound());

        try {
            $this->warrants->arrest($hunter->fresh(), $warrant->fresh(), 0.0);
            $this->fail('They slipped away.');
        } catch (GameException $e) {
            $this->assertStringContainsString('slipped away', $e->getMessage());
        }

        $this->travel(31)->minutes();
        $this->assertTrue($this->warrants->arrest($hunter->fresh(), $warrant->fresh(), 0.0));
    }

    public function test_a_new_season_lapses_open_warrants_and_hands_their_apples_back(): void
    {
        [$house, $ruler, $suspect] = $this->houseAndSuspect();
        $warrant = $this->warrants->issue($ruler, $suspect, 100);
        $helper = $this->player();
        Apples::award($helper, 30, 'Test', 'test:helper');
        $this->warrants->raise($helper->fresh(), $warrant, 20);
        $this->warrants->raise($helper->fresh(), $warrant, 5);
        $this->assertSame(400, $house->fresh()->apples);
        $this->assertSame(5, $helper->fresh()->apples);

        app(Seasons::class)->end($this->valley, $this->season());

        $this->assertSame('lapsed', Warrant::sole()->status);
        $this->assertSame(500, $house->fresh()->apples, 'The House gets its 100 Apples back.');
        $this->assertSame(30, $helper->fresh()->apples, 'The helper gets the 25 Apples they added back.');
        $this->assertSame(30, (int) AppleEntry::query()->where('player_id', $helper->id)->sum('amount'));
        $this->assertTrue(Notice::query()->where('player_id', $helper->id)->where('text', 'like', '%25 Apples you added to the bounty came back%')->exists());
    }

    public function test_the_wanted_board_and_the_map_show_warrants(): void
    {
        [, $ruler, $suspect] = $this->houseAndSuspect();

        $this->actingAs($ruler->user)->get(route('play.wanted'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('game/wanted')
            ->has('house.suspects', 1)
            ->where('house.suspects.0.animal.name', $suspect->name)
            ->where('house.suspects.0.deed', 'looted Victims'));

        $warrant = $this->warrants->issue($ruler, $suspect, 150);

        $this->actingAs($ruler->user)->get(route('play'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('warrants.0.id', $warrant->id)
            ->where('warrants.0.playerId', $suspect->id)
            ->where('warrants.0.bounty', 150));
    }

    public function test_the_map_shows_you_and_an_outlaw_even_in_a_crowd(): void
    {
        // Twenty-four animals got to the Henhouse first.
        foreach (range(1, 24) as $i) {
            $this->stand($this->player(), 'henhouse');
        }
        [, $ruler, $suspect] = $this->houseAndSuspect();
        $this->stand($suspect, 'henhouse');
        $this->stand($ruler, 'henhouse');
        $this->warrants->issue($ruler, $suspect, 150);

        $this->actingAs($ruler->user)->get(route('play'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('regions.11.key', 'henhouse')
            ->where('regions.11.animals.count', 26)
            ->has('regions.11.animals.sample', 24)
            ->where('regions.11.animals.sample.0.id', $ruler->id)
            ->where('regions.11.animals.sample.0.me', true)
            ->where('regions.11.animals.sample.1.id', $suspect->id));
    }
}
