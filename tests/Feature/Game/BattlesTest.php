<?php

namespace Tests\Feature\Game;

use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\RaidRules;
use App\Game\Stock;
use App\Models\Attack;
use App\Models\Faction;
use App\Models\Notice;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Raids: who can attack what, the march, and how the tick settles them.
 */
class BattlesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Battles $battles;

    /** @var array<int, Player> */
    private array $red = [];

    private Faction $redHooves;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->battles = app(Battles::class);
        $this->travelTo(Clock::now()->setTime(12, 0));
        // Noon may be earlier than the season started today: start it the day before, so what happens now counts this season.
        $this->season()->forceFill(['starts_at' => now()->subDay()])->save();

        $this->red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $this->redHooves = $this->faction('Red Hooves', ...$this->red);
    }

    public function test_a_faction_needs_three_members_to_raid(): void
    {
        $loner = $this->player();
        $this->faction('Too Small', $loner, $this->player());

        $this->expectException(GameException::class);
        $this->battles->launch($loner, $this->region('henhouse'), 1);
    }

    public function test_any_region_can_be_raided_from_anywhere(): void
    {
        // No land, nowhere near it: the raid still goes.
        $this->stand($this->red[0], 'paddock');
        $attack = $this->battles->launch($this->red[0]->fresh(), $this->region('orchard'), 1);

        $this->assertSame($this->region('orchard')->id, $attack->region_id);
    }

    public function test_the_farmhouse_needs_two_of_its_four_neighbours(): void
    {
        $this->hold($this->redHooves, 'market_square');

        try {
            $this->battles->launch($this->red[0], $this->region('farmhouse'), 1);
            $this->fail('One neighbour is not enough.');
        } catch (GameException $e) {
            $this->assertStringContainsString('2 of the 4 regions', $e->getMessage());
        }

        $this->hold($this->redHooves, 'granary');
        $this->stand($this->red[0], 'granary');
        $attack = $this->battles->launch($this->red[0]->fresh(), $this->region('farmhouse'), 1);

        $this->assertEqualsWithDelta(120, $attack->created_at->diffInMinutes($attack->lands_at), 1, 'The Farmhouse march takes two hours.');
    }

    public function test_an_unheld_region_falls_to_any_attack_that_lands(): void
    {
        $attack = $this->battles->launch($this->red[0], $this->region('henhouse'), 1);

        $this->travel(61)->minutes();
        $this->battles->resolveDue($this->season());

        $this->assertSame('attack', $attack->fresh()->winner);
        $this->assertSame($this->redHooves->id, $this->region('henhouse')->faction_id);
        $this->assertSame(10, $this->red[0]->fresh()->rosettes - 0);

        // The raid's leader strips the field bare into their bag.
        $this->assertSame(Stock::rule($this->region('henhouse'))['max'], $this->red[0]->fresh()->material('eggs'));
        $this->assertSame(0, Stock::state($this->region('henhouse'))['left']);
    }

    public function test_a_raid_lands_when_its_leader_walks_onto_the_land(): void
    {
        config(['game.raids.on_arrival' => true]);
        $attack = $this->battles->launch($this->red[0], $this->region('henhouse'), 1);
        $leader = $this->red[0]->fresh();

        // The leader walks there, and the raid lands as they arrive.
        $this->assertNotNull($leader->walk_arrives_at);
        $this->assertTrue($attack->lands_at->equalTo($leader->walk_arrives_at));
        $this->assertTrue($attack->lands_at->lt(now()->addMinutes(Battles::MARCH_MINUTES)));

        // A red line on the leader's map.
        $this->actingAs($leader->user)->get(route('play'))->assertInertia(fn ($page) => $page
            ->where('strikes.0.kind', 'raid')
            ->where('strikes.0.mine', true));

        $this->travelTo($attack->lands_at->addSecond());
        $this->battles->resolveDue($this->season());
        $this->assertSame('attack', $attack->fresh()->winner);
    }

    public function test_defenders_who_turn_up_hold_their_land(): void
    {
        $blue = [$this->player(Species::Dog), $this->player(Species::Dog), $this->player(Species::Dog)];
        $blueFaction = $this->faction('Blue Guard', ...$blue);
        $this->hold($blueFaction, 'henhouse');

        $attack = $this->battles->launch($this->red[0], $this->region('henhouse'), 1);
        $this->battles->commit($blue[0], $attack, 3);
        $this->battles->commit($blue[1], $attack, 3);

        $this->travel(61)->minutes();
        $this->battles->resolveDue($this->season());

        $this->assertSame('defence', $attack->fresh()->winner);
        $this->assertSame($blueFaction->id, $this->region('henhouse')->faction_id);
        $this->assertSame(8, $blue[0]->fresh()->rosettes);
    }

    public function test_only_the_two_factions_can_join_a_battle(): void
    {
        $attack = $this->battles->launch($this->red[0], $this->region('henhouse'), 1);

        $this->expectExceptionMessage("Only the attackers' and defenders' factions");
        $this->battles->commit($this->player(), $attack, 1);
    }

    public function test_taking_the_farmhouse_holds_it_but_ruling_waits_for_a_head_office(): void
    {
        $this->hold($this->redHooves, 'market_square', 'granary');
        $this->stand($this->red[0], 'market_square');
        $attack = $this->battles->launch($this->red[0]->fresh(), $this->region('farmhouse'), 3);
        $this->battles->commit($this->red[1], $attack, 3);

        $this->travel(121)->minutes();
        $this->battles->resolveDue($this->season());

        $this->assertSame($this->redHooves->id, $this->region('farmhouse')->faction_id);
        $this->assertNull($this->valley->fresh()->house());
        $this->assertSame(0, $this->season()->fresh()->bonus_law_changes, 'The extra law changes come with the Head Office.');
        $this->assertTrue($this->region('farmhouse')->settled_until->isFuture(), 'A region that just changed hands gets an hour of peace.');
    }

    public function test_attacks_that_would_land_in_the_quiet_hours_wait_until_six(): void
    {
        $this->travelTo(Clock::now()->setTime(0, 30));

        $attack = $this->battles->launch($this->red[0], $this->region('henhouse'), 1);

        $this->assertSame('06:00', Clock::local($attack->lands_at)->format('H:i'));
    }

    public function test_big_empires_pay_upkeep_or_lose_their_newest_land(): void
    {
        $this->hold($this->redHooves, 'orchard', 'hayfield', 'quarry', 'wildwood');
        $this->region('sheepfold')->forceFill(['faction_id' => $this->redHooves->id, 'held_since' => now()])->save();
        $this->redHooves->forceFill(['treasury' => 10])->save();

        $this->battles->upkeep($this->season());
        $this->assertSame(8, $this->redHooves->fresh()->treasury, 'Five regions cost 2 Corn a tick.');

        $this->redHooves->forceFill(['treasury' => 1])->save();
        $this->battles->upkeep($this->season());

        $this->assertNull($this->region('sheepfold')->faction_id, 'The newest region is lost when upkeep cannot be paid.');
        $this->assertSame(4, $this->redHooves->regions()->count());
    }

    public function test_breaking_the_factions_raid_rules_earns_strikes_and_a_flag(): void
    {
        $leader = $this->red[0];
        $leader->forceFill(['faction_role' => 'leader'])->save();
        $member = $this->red[1];
        $member->forceFill(['faction_role' => 'member'])->save();
        app(RaidRules::class)->set($leader->fresh(), ['front_line', 'near_land', 'leader_call'], [], false);
        $this->stand($member, 'paddock');

        // Far from the orchard, next to no land of ours, and not the leader's call: three strikes, but the raid goes.
        $this->battles->launch($member->fresh(), $this->region('orchard'), 1);
        $this->assertSame(3, RaidRules::strikes($this->redHooves->fresh())->get($member->id));
        $this->assertStringContainsString('flagged', Notice::query()->where('player_id', $leader->id)->where('text', 'like', '%raid rules%')->latest('id')->value('text'));

        // Excused: no more strikes.
        app(RaidRules::class)->exempt($leader->fresh(), $member->fresh(), true);
        $this->battles->launch($member->fresh(), $this->region('quarry'), 1);
        $this->assertSame(3, RaidRules::strikes($this->redHooves->fresh())->get($member->id));

        app(RaidRules::class)->forgive($leader->fresh(), $member->fresh());
        $this->assertSame(0, RaidRules::strikes($this->redHooves->fresh())->get($member->id, 0));
    }

    public function test_nobody_can_attack_the_paddock(): void
    {
        $this->expectExceptionMessage('safe ground');
        $this->battles->launch($this->red[0], $this->region('paddock'), 1);
    }

    public function test_the_map_route_launches_an_attack(): void
    {
        $this->actingAs($this->red[0]->user)
            ->post(route('play.attack', $this->region('stables')), ['horseshoes' => 2])
            ->assertRedirect();

        $this->assertSame(1, Attack::count());
    }
}
