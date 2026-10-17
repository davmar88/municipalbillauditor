<?php

namespace Tests\Feature\Game;

use App\Enums\FactionRole;
use App\Enums\Species;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\Horseshoes;
use App\Game\LawTemplate;
use App\Game\Wall;
use App\Models\Faction;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Barn Wall: who may change it, its limits, and the weekly secret edit.
 */
class WallTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Wall $wall;

    private Player $leader;

    private Faction $house;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->wall = app(Wall::class);
        $this->leader = $this->player();
        $this->house = $this->faction('The House', $this->leader);
        $this->hold($this->house, 'farmhouse');
    }

    public function test_only_the_houses_leader_and_council_paint_laws(): void
    {
        $outsider = $this->player();

        $this->actingAs($outsider->user)->post(route('play.wall.law'), ['slot' => 1, 'template' => 'grain_tax', 'value' => 10]);
        $this->assertSame(0, Law::count());

        $this->actingAs($this->leader->user)->post(route('play.wall.law'), ['slot' => 1, 'template' => 'grain_tax', 'value' => 10])->assertRedirect();

        $law = Law::sole();
        $this->assertSame(LawTemplate::GrainTax, $law->template);
        $this->assertSame(10, $law->value);
        $this->assertSame('Every animal gives 10% of the Corn it gathers to the Farmhouse.', $law->wording());
        $this->assertSame(1, LawVersion::query()->where('secret', false)->count());
    }

    public function test_laws_stay_inside_their_limits(): void
    {
        $this->expectException(GameException::class);

        $this->wall->setLaw($this->leader, 1, LawTemplate::MarketLevy, 9);
    }

    public function test_the_house_gets_three_changes_a_day(): void
    {
        $this->wall->setLaw($this->leader, 1, LawTemplate::GrainTax, 5);
        $this->wall->setLaw($this->leader, 2, LawTemplate::MarketLevy, 2);
        $this->wall->setLaw($this->leader, 3, LawTemplate::Curfew, 4);

        try {
            $this->wall->setLaw($this->leader, 4, LawTemplate::NightWatch);
            $this->fail('A fourth change should be refused.');
        } catch (GameException $e) {
            $this->assertStringContainsString('used its law changes', $e->getMessage());
        }

        $this->travelTo(Clock::now()->addDay()->setTime(9, 0));
        $this->assertNotNull($this->wall->setLaw($this->leader, 4, LawTemplate::NightWatch));
    }

    public function test_a_trade_ban_names_another_faction_and_runs_out_after_72_hours(): void
    {
        $rival = $this->faction('Rivals', $this->player());

        $law = $this->wall->setLaw($this->leader, 1, LawTemplate::TradeBan, null, (string) $rival->id);
        $this->assertSame('Rivals may not trade in the market.', $law->wording());

        $this->travel(73)->hours();
        $this->wall->expireLaws($this->season());

        $this->assertSame(0, Law::count());
    }

    public function test_the_secret_edit_only_works_for_the_leader_between_midnight_and_four_once_a_week(): void
    {
        $this->wall->setLaw($this->leader, 1, LawTemplate::MarketLevy, 2);
        $council = $this->player();
        $council->forceFill(['faction_id' => $this->house->id, 'faction_role' => FactionRole::Council])->save();

        $this->travelTo(Clock::now()->addDay()->setTime(14, 0));
        $this->assertRefusedWith(fn () => $this->wall->secretEdit($this->leader->fresh(), 1, 4), 'between midnight and 04:00');

        $this->travelTo(Clock::now()->addDay()->setTime(2, 13));
        $this->assertRefusedWith(fn () => $this->wall->secretEdit($council->fresh(), 1, 4), 'Only the leader');

        $this->wall->secretEdit($this->leader->fresh(), 1, 4);
        $this->assertSame(4, Law::sole()->value);
        $this->assertSame(1, LawVersion::query()->where('secret', true)->count());
        $this->assertCount(1, Wall::history($this->season()), 'The secret edit stays out of the public history.');

        $this->travelTo(Clock::now()->addDay()->setTime(1, 0));
        $this->assertRefusedWith(fn () => $this->wall->secretEdit($this->leader->fresh(), 1, 3), 'this week');
    }

    public function test_a_player_with_an_older_copy_can_expose_the_edit(): void
    {
        $this->wall->setLaw($this->leader, 2, LawTemplate::MarketLevy, 2);
        $donkey = $this->player(Species::Goat);
        $late = $this->player();

        $this->wall->remember($donkey);

        $this->travelTo(Clock::now()->addDay()->setTime(2, 13));
        $this->wall->secretEdit($this->leader->fresh(), 2, 4);
        $unrestBefore = Wall::unrest($this->season());

        $this->travelTo(Clock::now()->setTime(7, 40));
        $result = $this->wall->expose($donkey, 2);

        $this->assertTrue($result['correct']);
        $this->assertSame(1, $result['rank']);
        $this->assertSame(50, $result['rosettes']);
        $this->assertSame(Wall::unrest($this->season()), $unrestBefore + 15);
        $this->assertNotNull(LawVersion::query()->where('secret', true)->value('exposed_at'));
        $this->assertCount(2, Wall::history($this->season()), 'Once exposed, the edit shows in the history.');

        // A copy made after the edit proves nothing.
        $this->wall->remember($late);
        $this->assertFalse($this->wall->expose($late, 2)['correct']);
        $this->assertSame(8, Horseshoes::current($late->fresh()), 'A wrong claim costs 2 horseshoes.');
    }

    public function test_an_edit_nobody_catches_settles_in_and_earns_the_house_a_bonus_change(): void
    {
        $this->wall->setLaw($this->leader, 1, LawTemplate::GrainTax, 5);
        $goat = $this->player(Species::Goat);

        $this->travelTo(Clock::now()->addDay()->setTime(1, 0));
        $this->wall->secretEdit($this->leader->fresh(), 1, 9);

        $this->travel(5)->hours();
        $this->assertSame([], Wall::goatHints($goat, $this->season()));
        $this->travel(2)->hours();
        $this->assertSame([1], Wall::goatHints($goat, $this->season()), 'Goats see it after six hours.');

        $this->travel(66)->hours();
        $this->wall->settleSecretEdits($this->season());

        $this->assertSame(1, $this->season()->fresh()->bonus_law_changes);
        $this->assertSame('settled', Wall::history($this->season())->first()['kind']);
    }

    public function test_remember_works_once_a_day(): void
    {
        $player = $this->player();
        $this->wall->remember($player);

        $this->expectException(GameException::class);
        $this->wall->remember($player);
    }

    public function test_a_festival_costs_the_treasury_and_calms_the_valley(): void
    {
        $this->house->forceFill(['treasury' => 600])->save();
        $before = Wall::unrest($this->season());

        $this->wall->festival($this->leader);

        $this->assertSame(100, $this->house->fresh()->treasury);
        $this->assertSame(max(0, $before - 10), Wall::unrest($this->season()));
        $this->assertRefusedWith(fn () => $this->wall->festival($this->leader), 'once every three days');
    }

    public function test_sheep_chant_for_their_own_house_and_against_a_rival(): void
    {
        $this->house->forceFill(['motto' => 'Order and bread'])->save();
        $loyal = $this->player(Species::Sheep);
        $loyal->forceFill(['faction_id' => $this->house->id, 'faction_role' => FactionRole::Member])->save();

        $rebels = $this->faction('Rebels', $this->player());
        $rebels->forceFill(['motto' => 'Bread for all'])->save();
        $rebel = $this->player(Species::Sheep);
        $rebel->forceFill(['faction_id' => $rebels->id, 'faction_role' => FactionRole::Member])->save();

        $this->assertSame(-1, $this->wall->chant($loyal->fresh()));
        $this->assertSame(1, $this->wall->chant($rebel->fresh()));
        $this->assertRefusedWith(fn () => $this->wall->chant($rebel->fresh()), 'already chanted');
    }

    public function test_the_wall_page_shows_the_laws_and_the_secret_edit_controls_to_the_leader(): void
    {
        $this->wall->setLaw($this->leader, 1, LawTemplate::GrainTax, 5);

        $this->actingAs($this->leader->user)->get(route('play.wall'))->assertInertia(fn (Assert $page) => $page
            ->component('game/wall')
            ->has('laws', 5)
            ->where('laws.0.label', 'Grain Tax')
            ->where('canDecide', true)
            ->where('isLeader', true)
            ->where('changesLeft', 2));
    }

    private function assertRefusedWith(callable $attempt, string $because): void
    {
        try {
            $attempt();
            $this->fail("Expected a refusal ({$because}).");
        } catch (GameException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }
}
