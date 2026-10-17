<?php

namespace Tests\Feature\Game;

use App\Enums\BuildingKind;
use App\Enums\Species;
use App\Game\Bases;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\Gathering;
use App\Game\Seasons;
use App\Models\Building;
use App\Models\Faction;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Faction bases: the store, the Den first, levels, what buildings do, and
 * how they fall with the land.
 */
class BasesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Bases $bases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->bases = app(Bases::class);
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    private function stock(Faction $faction, int $corn = 1000, int $each = 200): Faction
    {
        $faction->forceFill(['treasury' => $corn, 'materials' => array_fill_keys(Bases::MATERIALS, $each)])->save();

        return $faction->fresh();
    }

    private function raise(Faction $faction, string $key, BuildingKind $kind, int $level = 1): Building
    {
        return Building::create([
            'faction_id' => $faction->id,
            'region_id' => $this->region($key)->id,
            'kind' => $kind,
            'level' => $level,
            'ready_at' => now()->subMinute(),
        ]);
    }

    public function test_members_give_corn_and_materials_to_the_store(): void
    {
        $member = $this->player(attributes: ['corn' => 50, 'materials' => ['wood' => 12]]);
        $faction = $this->faction('Givers', $this->player(), $member);

        $this->actingAs($member->user)->post(route('play.give'), ['what' => 'wood', 'amount' => 10])->assertRedirect();
        $this->bases->give($member->fresh(), 'corn', 30);

        $this->assertSame(2, $member->fresh()->material('wood'));
        $this->assertSame(20, $member->fresh()->corn);
        $this->assertSame(10, $faction->fresh()->material('wood'));
        $this->assertSame(30, $faction->fresh()->treasury);

        $this->expectExceptionMessage('You have 2 wood');
        $this->bases->give($member->fresh(), 'wood', 5);
    }

    public function test_the_den_comes_first_and_a_faction_has_one(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader));
        $this->hold($faction, 'granary', 'henhouse');

        try {
            $this->bases->build($leader, $this->region('granary'), BuildingKind::Watchtower);
            $this->fail('A Watchtower before a Den.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Raise a Den first', $e->getMessage());
        }

        $this->bases->build($leader, $this->region('granary'), BuildingKind::Den);
        $this->bases->build($leader, $this->region('henhouse'), BuildingKind::Watchtower);

        $this->expectExceptionMessage('already has its Den');
        $this->region('sheepfold')->forceFill(['faction_id' => $faction->id])->save();
        $this->bases->build($leader, $this->region('sheepfold'), BuildingKind::Den);
    }

    public function test_the_leader_builds_and_pays_from_the_store(): void
    {
        $leader = $this->player();
        $member = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader, $member), corn: 100, each: 30);
        $this->hold($faction, 'granary');

        $this->actingAs($member->user)->post(route('play.build', $this->region('granary')), ['kind' => 'den'])->assertRedirect();
        $this->assertSame(0, Building::count(), 'Members give; the leader and council build.');

        $this->actingAs($leader->user)->post(route('play.build', $this->region('granary')), ['kind' => 'den'])->assertRedirect();

        $faction = $faction->fresh();
        $this->assertSame(20, $faction->treasury);
        $this->assertSame(10, $faction->material('wood'));
        $this->assertSame(10, $faction->material('straw'));

        $den = Building::sole();
        $this->assertFalse($den->isReady());
        $this->assertSame(0, $den->workingLevel(), 'Nothing works until it is built.');
    }

    public function test_a_short_store_says_what_is_missing(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Poor', $leader);
        $this->hold($faction, 'granary');

        $this->expectExceptionMessage('short of 80 Corn, 20 wood and 20 straw');
        $this->bases->build($leader, $this->region('granary'), BuildingKind::Den);
    }

    public function test_nothing_is_built_on_land_you_dont_hold_or_at_the_farmhouse(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader));
        $this->hold($faction, 'farmhouse');

        try {
            $this->bases->build($leader, $this->region('granary'), BuildingKind::Den);
            $this->fail('Granary is not theirs.');
        } catch (GameException $e) {
            $this->assertStringContainsString("doesn't hold Granary", $e->getMessage());
        }

        $this->expectExceptionMessage('Nothing can be built in The Farmhouse');
        $this->bases->build($leader, $this->region('farmhouse'), BuildingKind::Den);
    }

    public function test_a_level_goes_up_once_the_last_is_ready_and_keeps_working_meanwhile(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader));
        $this->hold($faction, 'granary');
        $den = $this->bases->build($leader, $this->region('granary'), BuildingKind::Den);

        try {
            $this->bases->upgrade($leader, $den);
            $this->fail('Still going up.');
        } catch (GameException $e) {
            $this->assertStringContainsString('still going up', $e->getMessage());
        }

        $this->travel(61)->minutes();
        $this->assertSame(1, $den->fresh()->workingLevel());

        $this->actingAs($leader->user)->post(route('play.upgrade', $den))->assertRedirect();

        $den = $den->fresh();
        $this->assertSame(2, $den->level);
        $this->assertSame(1, $den->workingLevel(), 'Level 1 keeps working while level 2 goes up.');
        $this->assertSame(17, Bases::memberCap($faction->fresh()));
    }

    public function test_a_watchtower_holds_land_that_would_have_fallen(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $this->faction('Red Hooves', ...$red);
        $blue = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $blueFaction = $this->faction('Blue Guard', ...$blue);
        $this->hold($blueFaction, 'henhouse');
        $this->raise($blueFaction, 'henhouse', BuildingKind::Watchtower, 3);
        $battles = app(Battles::class);

        // 5 horseshoes at 1.25 for a landless faction against 4 at 1.2 in defence:
        // the attackers win every roll without the tower, and lose every roll with it.
        $attack = $battles->launch($red[0], $this->region('henhouse'), 3);
        $battles->commit($red[1], $attack, 2);
        $battles->commit($blue[0], $attack, 3);
        $battles->commit($blue[1], $attack, 1);

        $this->travel(61)->minutes();
        $battles->resolveDue($this->season());

        $attack = $attack->fresh();
        $this->assertSame('defence', $attack->winner);
        $this->assertGreaterThan((float) $attack->defence_strength / 1.6, (float) $attack->attack_strength, 'Without the tower the raid would have won.');
    }

    public function test_drill_yards_next_door_make_raids_hit_harder(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Drillers', $leader);
        $this->hold($faction, 'granary');
        $this->raise($faction, 'granary', BuildingKind::DrillYard, 2);

        $this->assertEqualsWithDelta(1.2, Bases::attack($faction->id, $this->region('henhouse')), 0.001);
        $this->assertEqualsWithDelta(1.0, Bases::attack($faction->id, $this->region('stables')), 0.001, 'Only regions next to the yard.');
    }

    public function test_raiders_who_take_a_den_carry_off_a_fifth_of_the_treasury(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $redFaction = $this->faction('Red Hooves', ...$red);
        $blueFaction = $this->faction('Blue Guard', $this->player());
        $blueFaction->forceFill(['treasury' => 1000])->save();
        $this->hold($blueFaction, 'henhouse');
        $this->raise($blueFaction, 'henhouse', BuildingKind::Den);

        $attack = app(Battles::class)->launch($red[0], $this->region('henhouse'), 1);
        $this->travel(61)->minutes();
        app(Battles::class)->resolveDue($this->season());

        $this->assertSame('attack', $attack->fresh()->winner);
        $this->assertSame(0, Building::count(), 'The Den fell with its region.');
        $this->assertSame(800, $blueFaction->fresh()->treasury);
        $this->assertSame(200, $redFaction->fresh()->treasury);
    }

    public function test_a_storehouse_keeps_looters_to_a_smaller_share(): void
    {
        $faction = $this->faction('Hoarders', $this->player());
        $this->hold($faction, 'duck_pond');

        $this->assertEqualsWithDelta(0.10, Bases::lootShare($faction), 0.0001);

        $this->raise($faction, 'duck_pond', BuildingKind::Storehouse, 3);
        $this->assertEqualsWithDelta(0.02, Bases::lootShare($faction), 0.0001);
    }

    public function test_a_workshop_gives_more_materials_where_it_stands(): void
    {
        $sheep = $this->player(Species::Sheep);
        $faction = $this->faction('Wool Co', $sheep);
        $this->hold($faction, 'sheepfold');
        $this->raise($faction, 'sheepfold', BuildingKind::Workshop);
        $this->stand($sheep, 'sheepfold');

        app(Gathering::class)->gather($sheep, $this->region('sheepfold'));

        $this->assertSame(9, $sheep->fresh()->material('wool'), '5 wool, half again on your own land, a quarter more from the Workshop.');
    }

    public function test_buildings_fall_with_land_lost_any_other_way(): void
    {
        $faction = $this->faction('Unlucky', $this->player());
        $this->hold($faction, 'old_well', 'stables');
        $this->raise($faction, 'old_well', BuildingKind::Den);
        $this->raise($faction, 'stables', BuildingKind::Workshop);

        Region::query()->whereKey($this->region('old_well')->id)->update(['faction_id' => null]);
        $this->bases->clearRuins($this->valley);

        $this->assertSame([BuildingKind::Workshop], Building::all()->pluck('kind')->all());
    }

    public function test_a_new_season_clears_the_buildings_and_shrinks_the_store(): void
    {
        $faction = $this->stock($this->faction('Builders', $this->player()), each: 45);
        $this->hold($faction, 'granary');
        $this->raise($faction, 'granary', BuildingKind::Den);

        app(Seasons::class)->end($this->valley, $this->season());

        $this->assertSame(0, Building::count());
        $this->assertSame(4, $faction->fresh()->material('wood'));
    }

    public function test_the_map_and_faction_page_show_the_base(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader));
        $this->hold($faction, 'granary', 'henhouse');
        $this->raise($faction, 'granary', BuildingKind::Den, 2);

        $this->actingAs($leader->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('regions.6.key', 'granary')
            ->where('regions.6.building.label', 'Den')
            ->where('regions.6.building.level', 2)
            ->where('regions.11.building', null)
            ->where('base.canBuild', true)
            ->where('base.hasDen', true)
            ->has('buildingKinds', 5));

        $this->actingAs($leader->user)->get(route('play.faction'))->assertInertia(fn (Assert $page) => $page
            ->has('faction.base.buildings', 1)
            ->where('faction.base.buildings.0.region.name', 'Granary')
            ->has('faction.base.plots', 1)
            ->where('faction.base.plots.0.name', 'Henhouse')
            ->where('faction.base.memberCap', 19));
    }
}
