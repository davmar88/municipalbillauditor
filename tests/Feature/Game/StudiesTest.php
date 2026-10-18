<?php

namespace Tests\Feature\Game;

use App\Enums\Species;
use App\Enums\Study;
use App\Game\Bases;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\GateNight;
use App\Game\Research;
use App\Game\Tick;
use App\Models\Faction;
use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Faction studies: starting them, paying for them, finishing them, what
 * they do, and Ironfield sending more men as factions learn.
 */
class StudiesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Research $research;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->research = app(Research::class);
        $this->travelTo(Clock::now()->next('Monday')->setTime(12, 0));
    }

    private function stock(Faction $faction, int $crops = 200): Faction
    {
        $materials = array_fill_keys(Bases::MATERIALS, 200);
        $materials = [...$materials, 'honey' => $crops, 'herbs' => $crops, 'iron' => $crops];
        $faction->forceFill(['treasury' => 1000, 'materials' => $materials])->save();

        return $faction->fresh();
    }

    public function test_the_leader_starts_a_study_paid_from_the_store_and_it_finishes_on_the_tick(): void
    {
        $leader = $this->player();
        $member = $this->player();
        $faction = $this->stock($this->faction('Scholars', $leader, $member));

        try {
            $this->research->start($member, Study::Attack);
            $this->fail('Members give; the leader and council study.');
        } catch (GameException $e) {
            $this->assertStringContainsString("Only your faction's leader and council", $e->getMessage());
        }

        $this->actingAs($leader->user)->post(route('play.study'), ['study' => 'attack'])->assertRedirect();

        $faction->refresh();
        $this->assertSame(['attack', 940, 180], [$faction->studying, $faction->treasury, $faction->material('wood')]);
        $this->assertSame(0, Research::level($faction, Study::Attack));
        $this->assertStringContainsString('started your faction studying Attack 1', Notice::query()->where('player_id', $member->id)->latest('id')->value('text'));

        try {
            $this->research->start($leader->fresh(), Study::Defence);
            $this->fail('One study at a time.');
        } catch (GameException $e) {
            $this->assertStringContainsString('one thing at a time', $e->getMessage());
        }

        $this->travel(61)->minutes();
        app(Tick::class)->run($this->valley);

        $faction->refresh();
        $this->assertSame(['attack' => 1], $faction->studies);
        $this->assertNull($faction->studying);
        $this->assertEqualsWithDelta(1.05, Research::attack($faction->id), 0.0001);
        $this->assertSame('📚 Your faction finished studying Attack 1. Your raids hit 5% harder.', Notice::query()->where('player_id', $member->id)->latest('id')->value('text'));
    }

    public function test_science_speeds_studies_and_the_higher_levels_need_the_farms_crops(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Scholars', $leader), crops: 0);
        $faction->forceFill(['studies' => ['science' => 2, 'attack' => 2]])->save();

        $this->assertSame(48, Research::minutes($faction, Study::Farming, 1), 'Science 2 takes a fifth off the hour.');
        $this->assertSame(288, Research::minutes($faction, Study::Attack, 3));

        try {
            $this->research->start($leader, Study::Attack);
            $this->fail('Attack 3 needs iron from the farms.');
        } catch (GameException $e) {
            $this->assertSame('The store is short of 10 iron. Members can give from the Faction page.', $e->getMessage());
        }

        $faction->forceFill(['studies' => ['marching' => 5]])->save();
        $this->expectExceptionMessage('learned all there is of Marching');
        $this->research->start($leader->fresh(), Study::Marching);
    }

    public function test_marching_farming_and_defence_make_the_faction_stronger(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Scholars', $leader);
        $faction->forceFill(['studies' => ['marching' => 2, 'farming' => 2, 'defence' => 3]])->save();

        $this->assertSame(48, Research::march($faction->id, 60), 'Raids land a fifth sooner.');
        $this->assertSame(96, Research::march($faction->id, 120));
        $this->assertEqualsWithDelta(1.1, Research::gathering($leader->fresh()), 0.0001);
        $this->assertEqualsWithDelta(1.15, Research::defence($faction->id), 0.0001);
        $this->assertEqualsWithDelta(1.0, Research::attack(null), 0.0001, 'Lone wolves study nothing.');
    }

    public function test_ironfield_sends_more_men_as_the_strongest_faction_studies(): void
    {
        $this->assertSame(6, GateNight::humans($this->valley));

        $dog = $this->player(Species::Dog);
        $this->faction('Scholars', $dog)->forceFill(['studies' => ['attack' => 3, 'defence' => 2]])->save();
        $this->faction('Others', $this->player())->forceFill(['studies' => ['farming' => 1]])->save();

        $this->assertSame(11, GateNight::humans($this->valley), 'Six men, and one for each of the strongest faction\'s five levels.');

        // Defence makes the faction's members stronger at the gate too.
        $this->travelTo(Clock::now()->next('Sunday')->setTime(15, 0));
        app(GateNight::class)->defend($dog->fresh(), 3);
        $this->assertEqualsWithDelta(4.3, GateNight::tonight($this->season())['strength'], 0.001);
    }

    public function test_the_faction_page_shows_the_studies(): void
    {
        $leader = $this->player();
        $this->faction('Scholars', $leader)->forceFill(['studies' => ['attack' => 1]])->save();

        $this->actingAs($leader->user)->get(route('play.faction'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('faction.studies.points', 1)
            ->where('faction.studies.canStudy', true)
            ->where('faction.studies.studying', null)
            ->where('faction.studies.studies.0.value', 'attack')
            ->where('faction.studies.studies.0.level', 1)
            ->where('faction.studies.studies.0.does', 'Your raids hit 5% harder.')
            ->where('faction.studies.studies.0.next.corn', 150)
            ->where('faction.studies.studies.0.next.materials.wood', 35)
            ->where('faction.studies.studies.0.next.minutes', 180));
    }
}
