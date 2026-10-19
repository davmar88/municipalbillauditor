<?php

namespace Tests\Feature\Game;

use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Farms;
use App\Game\GameException;
use App\Game\Movement;
use App\Models\Faction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Farms growing into the wild land round them: each growth clears a patch
 * into a new region of the farm, held by the faction that grew it.
 */
class GrowingFarmsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Farms $farms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->farms = app(Farms::class);
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    private function stocked(Faction $faction): Faction
    {
        $faction->forceFill([
            'treasury' => 2000,
            'materials' => ['wood' => 200, 'stone' => 200, 'honey' => 100],
        ])->save();

        return $faction->fresh();
    }

    public function test_the_holder_grows_a_farm_into_its_wild_land_one_patch_at_a_time(): void
    {
        $leader = $this->player();
        $faction = $this->stocked($this->faction('Beekeepers', $leader));
        $this->hold($faction, 'thornbury');

        $this->actingAs($leader->user)->post(route('play.grow-farm', $this->region('thornbury')))->assertRedirect();

        $heath = $this->region('thornbury_heath');
        $this->assertFalse($heath->wild);
        $this->assertSame($faction->id, $heath->faction_id);
        $this->assertSame(1800, $faction->fresh()->treasury);
        $this->assertSame(180, $faction->fresh()->material('wood'));
        $this->assertSame(185, $faction->fresh()->material('stone'));

        $this->farms->grow($leader->fresh(), $this->region('thornbury'));
        $this->assertSame(90, $faction->fresh()->material('honey'), 'The second growth needs the farm\'s own crop.');
        $this->farms->grow($leader->fresh(), $this->region('thornbury'));

        $this->assertSame(3, Farms::grown($this->region('thornbury')));
        $this->expectExceptionMessage('grown into all the land round it');
        $this->farms->grow($leader->fresh(), $this->region('thornbury'));
    }

    public function test_only_the_holders_leaders_grow_a_farm(): void
    {
        $leader = $this->player();
        $member = $this->player();
        $faction = $this->stocked($this->faction('Beekeepers', $leader, $member));
        $rival = $this->player();
        $this->stocked($this->faction('Rivals', $rival));
        $this->hold($faction, 'thornbury');

        try {
            $this->farms->grow($member, $this->region('thornbury'));
            $this->fail('Members give; the leader and council grow.');
        } catch (GameException $e) {
            $this->assertStringContainsString('leader and council', $e->getMessage());
        }

        $this->expectExceptionMessage('must hold Thornbury Farm');
        $this->farms->grow($rival, $this->region('thornbury'));
    }

    public function test_wild_land_stays_off_the_map_and_out_of_reach(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $redFaction = $this->faction('Red Hooves', ...$red);
        $redFaction->forceFill(['treasury' => 1000])->save();
        $this->hold($redFaction, 'thornbury');
        $this->stand($red[0], 'thornbury');

        $this->actingAs($red[0]->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->has('regions', 59)
            ->where('regions.16.key', 'thornbury')
            ->where('regions.16.growth.grown', 0)
            ->where('regions.16.growth.next.name', 'Thornbury Heath'));

        foreach ([
            fn () => app(Movement::class)->walk($red[0]->fresh(), $this->region('thornbury_heath')),
            fn () => app(Battles::class)->launch($red[0]->fresh(), $this->region('thornbury_heath'), 1),
            fn () => $this->farms->buy($red[0]->fresh(), $this->region('thornbury_heath')),
        ] as $try) {
            try {
                $try();
                $this->fail('Wild land is out of reach.');
            } catch (GameException $e) {
                $this->assertStringContainsString('wild', $e->getMessage());
            }
        }
    }

    public function test_a_grown_patch_is_a_region_of_the_farm_raided_from_the_farm(): void
    {
        $keeper = $this->player();
        $keepers = $this->stocked($this->faction('Beekeepers', $keeper));
        $this->hold($keepers, 'thornbury');
        $this->farms->grow($keeper, $this->region('thornbury'));

        $this->actingAs($keeper->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->has('regions', 60)
            ->where('regions.19.key', 'thornbury_heath')
            ->where('regions.19.partOf', 'Thornbury Farm'));

        // Raiders must take the farm before they can reach its patches.
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $redFaction = $this->faction('Red Hooves', ...$red);
        $this->hold($redFaction, 'orchard');
        $this->stand($red[0], 'thornbury');
        $this->travel(61)->minutes();

        try {
            app(Battles::class)->launch($red[0]->fresh(), $this->region('thornbury_heath'), 1);
            $this->fail('The heath borders only its farm.');
        } catch (GameException $e) {
            $this->assertStringContainsString('next to one your faction holds', $e->getMessage());
        }

        $this->hold($redFaction, 'thornbury');
        app(Battles::class)->launch($red[0]->fresh(), $this->region('thornbury_heath'), 1);
        $this->travel(61)->minutes();
        app(Battles::class)->resolveDue($this->season());

        $this->assertSame($redFaction->id, $this->region('thornbury_heath')->faction_id);
    }
}
