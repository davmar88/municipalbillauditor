<?php

namespace Tests\Feature\Game;

use App\Enums\BuildingKind;
use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Game\Bases;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Dice;
use App\Game\GameException;
use App\Game\Gathering;
use App\Game\Horseshoes;
use App\Game\Seasons;
use App\Game\Stores;
use App\Game\Tick;
use App\Models\Building;
use App\Models\Faction;
use App\Models\Notice;
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

    public function test_nothing_is_built_on_land_you_dont_hold_and_only_a_head_office_at_the_farmhouse(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader));
        $this->region('farmhouse')->forceFill(['faction_id' => $faction->id, 'held_since' => now()])->save();
        $this->hold($faction, 'granary');

        try {
            $this->bases->build($leader, $this->region('henhouse'), BuildingKind::Den);
            $this->fail('Henhouse is not theirs.');
        } catch (GameException $e) {
            $this->assertStringContainsString("doesn't hold Henhouse", $e->getMessage());
        }

        try {
            $this->bases->build($leader, $this->region('farmhouse'), BuildingKind::Den);
            $this->fail('Only a Head Office in the Farmhouse.');
        } catch (GameException $e) {
            $this->assertSame('Only a Head Office can be built in the Farmhouse.', $e->getMessage());
        }

        $this->expectExceptionMessage('A Head Office goes in the Farmhouse');
        $this->bases->build($leader, $this->region('granary'), BuildingKind::HeadOffice);
    }

    public function test_a_faction_rules_once_its_head_office_stands_and_can_be_raided_while_it_goes_up(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader));
        $farmhouse = $this->region('farmhouse');
        $farmhouse->forceFill(['faction_id' => $faction->id, 'held_since' => now(), 'settled_until' => now()->addHour()])->save();
        $bystander = $this->player();

        $this->assertNull($this->valley->fresh()->house(), 'Holding the Farmhouse is not ruling.');

        // No Den needed. Starting the work ends the hour of peace after taking it.
        $office = $this->bases->build($leader, $farmhouse, BuildingKind::HeadOffice);
        $this->assertNull($farmhouse->fresh()->settled_until);
        $this->assertSame(700, $faction->fresh()->treasury);
        $this->assertStringContainsString('Builders is building a Head Office in the Farmhouse', Notice::query()->where('player_id', $bystander->id)->latest('id')->value('text'));

        $this->travel(119)->minutes();
        app(Bases::class)->crown($this->valley);
        $this->assertNull($this->valley->fresh()->house(), 'Not until it stands.');

        $this->travel(2)->minutes();
        app(Tick::class)->run($this->valley);

        $this->assertSame($faction->id, $this->valley->fresh()->house()?->id);
        $this->assertNotNull($office->fresh()->crowned_at);
        $this->assertSame(3, $this->season()->fresh()->bonus_law_changes);
        $this->assertSame('👑 Builders finished its Head Office in the Farmhouse and now rules Hollowmere.', Notice::query()->where('player_id', $bystander->id)->latest('id')->value('text'));
    }

    public function test_losing_the_farmhouse_while_the_head_office_goes_up_loses_it(): void
    {
        $leader = $this->player();
        $faction = $this->stock($this->faction('Builders', $leader));
        $farmhouse = $this->region('farmhouse');
        $farmhouse->forceFill(['faction_id' => $faction->id, 'held_since' => now()])->save();
        $this->bases->build($leader, $farmhouse, BuildingKind::HeadOffice);
        $raiders = $this->faction('Raiders', $this->player());

        app(Battles::class)->take($this->season(), $farmhouse->fresh(), $raiders->id);
        $this->travel(3)->hours();
        app(Bases::class)->crown($this->valley);

        $this->assertSame(0, Building::query()->where('kind', BuildingKind::HeadOffice)->count());
        $this->assertNull($this->valley->fresh()->house(), 'The raiders hold it, but rule only once they build their own.');
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

    public function test_raiders_who_take_a_building_carry_off_what_it_stored(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $redFaction = $this->faction('Red Hooves', ...$red);
        $blueFaction = $this->faction('Blue Guard', $this->player());
        $blueFaction->forceFill(['treasury' => 800, 'materials' => ['wood' => 200]])->save();
        $this->hold($blueFaction, 'henhouse');
        $this->raise($blueFaction, 'henhouse', BuildingKind::Den);
        $this->assertSame(['corn' => 800, 'material' => 250], Stores::capacity($blueFaction->fresh()));

        $attack = app(Battles::class)->launch($red[0], $this->region('henhouse'), 1);
        $this->travel(61)->minutes();
        app(Battles::class)->resolveDue($this->season());

        $this->assertSame('attack', $attack->fresh()->winner);
        $this->assertSame(0, Building::count(), 'The Den fell with its region.');

        // The Den held 500 of the 800 Corn the store had room for, and 150 of 250 of each material.
        $this->assertSame([300, 80], [$blueFaction->fresh()->treasury, $blueFaction->fresh()->material('wood')]);
        // The raiders keep what fits in their own store: 300 Corn and 100 wood in hand.
        $this->assertSame([300, 100], [$redFaction->fresh()->treasury, $redFaction->fresh()->material('wood')]);
        $this->assertStringContainsString('carried off 500 Corn and 120 materials', Notice::query()->where('player_id', $red[1]->id)->where('text', 'like', '📦%')->value('text'));
    }

    public function test_members_who_help_build_make_it_go_up_faster(): void
    {
        $leader = $this->player();
        $helpers = [$this->player(), $this->player(), $this->player()];
        $faction = $this->stock($this->faction('Builders', $leader, ...$helpers));
        $this->hold($faction, 'granary');
        $den = $this->bases->build($leader, $this->region('granary'), BuildingKind::Den);
        $this->assertSame(1, $den->builders());

        $this->travel(30)->minutes();

        try {
            $this->bases->help($helpers[0], $den);
            $this->fail('You help build where it stands.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Walk to Granary first', $e->getMessage());
        }

        // Thirty minutes of work were left: a second pair of hands makes that twenty.
        $this->stand($helpers[0], 'granary');
        $this->actingAs($helpers[0]->user)->post(route('play.help-build', $den))->assertRedirect();
        $this->assertEqualsWithDelta(20 * 60, now()->diffInSeconds($den->fresh()->ready_at), 2);
        $this->assertSame(9, Horseshoes::current($helpers[0]->fresh()));
        $this->assertSame(3, $helpers[0]->fresh()->rosettes);

        // A third makes what's left go twice as fast as it did alone.
        $this->stand($helpers[1], 'granary');
        $this->bases->help($helpers[1]->fresh(), $den->fresh());
        $this->assertEqualsWithDelta(15 * 60, now()->diffInSeconds($den->fresh()->ready_at), 2);
        $this->assertSame(3, $den->fresh()->builders());

        try {
            $this->bases->help($helpers[1]->fresh(), $den->fresh());
            $this->fail('Once per level.');
        } catch (GameException $e) {
            $this->assertStringContainsString('already helping', $e->getMessage());
        }

        $outsider = $this->player();
        $this->stand($outsider, 'granary');
        $this->expectExceptionMessage("Only Builders's members can help build");
        $this->bases->help($outsider->fresh(), $den->fresh());
    }

    public function test_a_full_store_takes_no_more_until_a_building_holds_it(): void
    {
        $member = $this->player(attributes: ['corn' => 1000, 'materials' => ['wood' => 500]]);
        $leader = $this->player();
        $faction = $this->faction('Givers', $leader, $member);
        $this->hold($faction, 'granary');

        $this->bases->give($member->fresh(), 'corn', 300);

        try {
            $this->bases->give($member->fresh(), 'corn', 1);
            $this->fail('The store holds 300 Corn without buildings.');
        } catch (GameException $e) {
            $this->assertStringContainsString('room for 0 more Corn', $e->getMessage());
        }

        $this->raise($faction, 'granary', BuildingKind::Storehouse);
        $this->bases->give($member->fresh(), 'corn', 700);
        $this->assertSame(1000, $faction->fresh()->treasury);
    }

    public function test_a_lone_wolf_loots_materials_from_the_store_too(): void
    {
        $faction = $this->faction('Hoarders', $this->player());
        $faction->forceFill(['treasury' => 100, 'materials' => ['wood' => 50, 'stone' => 30]])->save();
        $wolf = $this->player(level: 3);
        $crimes = app(Crimes::class);
        $crime = $crimes->commit($wolf, CrimeKind::Loot, $faction);
        $chance = $crimes->catchChance($this->season(), $crime);

        foreach (range(1, 1000) as $seed) {
            if (Dice::roll($seed, 'caught') >= $chance) {
                $crime->forceFill(['seed' => $seed])->save();
                break;
            }
        }

        $this->travel(31)->minutes();
        $crimes->resolveDue($this->season());

        $this->assertSame([90, 45, 27], [$faction->fresh()->treasury, $faction->fresh()->material('wood'), $faction->fresh()->material('stone')]);
        $this->assertSame([5, 3], [$wolf->fresh()->material('wood'), $wolf->fresh()->material('stone')]);
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
            ->has('buildingKinds', 6));

        $this->actingAs($leader->user)->get(route('play.faction'))->assertInertia(fn (Assert $page) => $page
            ->has('faction.base.buildings', 1)
            ->where('faction.base.buildings.0.region.name', 'Granary')
            ->has('faction.base.plots', 1)
            ->where('faction.base.plots.0.name', 'Henhouse')
            ->where('faction.base.memberCap', 19));
    }
}
