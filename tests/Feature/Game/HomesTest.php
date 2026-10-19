<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Crimes;
use App\Game\GameException;
use App\Game\Geography;
use App\Game\Homes;
use App\Game\Movement;
use App\Game\Warrants;
use App\Models\Crime;
use App\Models\Home;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Player;
use App\Models\Warrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every animal's own home: building it, raising it to a Fort, hiding in it,
 * and dressing it in a skin from the market.
 */
class HomesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Homes $homes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->homes = app(Homes::class);
    }

    /** An animal standing at a region's hub with what a Fort takes. */
    private function builder(string $region = 'granary', array $attributes = []): Player
    {
        $player = $this->player(attributes: ['corn' => 1000, 'materials' => ['wood' => 100, 'straw' => 100, 'stone' => 100, 'clay' => 100, 'iron' => 10], ...$attributes]);
        $this->stand($player, $region);
        [$x, $y] = Geography::hub($region);
        $player->forceFill(['pos_x' => $x, 'pos_y' => $y])->save();

        return $player->fresh();
    }

    public function test_an_animal_builds_a_hut_where_it_stands_and_raises_it_to_a_fort(): void
    {
        $player = $this->builder();

        $home = $this->homes->build($player);

        $this->assertSame(1, $home->level);
        $this->assertTrue($home->building());
        $this->assertSame(1000 - Homes::LEVELS[1]['corn'], $player->fresh()->corn);
        $this->assertSame(90, $player->fresh()->materials['wood']);

        try {
            $this->homes->upgrade($player->fresh());
            $this->fail('Raised a Hut still going up.');
        } catch (GameException $e) {
            $this->assertStringContainsString('still going up', $e->getMessage());
        }

        $this->travel(31)->minutes();
        $this->homes->upgrade($player->fresh());
        $this->travel(91)->minutes();
        $home = $this->homes->upgrade($player->fresh());

        $this->assertSame(3, $home->level);
        $this->assertSame('Fort', Homes::name($home->level));
        $this->assertSame(6, $player->fresh()->materials['iron']);

        $this->travel(181)->minutes();
        $this->expectExceptionMessage('already a Fort');
        $this->homes->upgrade($player->fresh());
    }

    public function test_no_homes_in_the_farmhouse_the_paddock_or_irongate_nor_on_top_of_another(): void
    {
        foreach (['farmhouse', 'paddock', 'irongate_yards'] as $key) {
            try {
                $this->homes->build($this->builder($key));
                $this->fail("Built a home in {$key}.");
            } catch (GameException $e) {
                $this->assertStringContainsString("Homes can't go", $e->getMessage());
            }
        }

        $this->homes->build($this->builder());
        $this->expectExceptionMessage('Another home stands right here');
        $this->homes->build($this->builder());
    }

    public function test_hiding_inside_keeps_you_off_the_map_until_you_walk_out(): void
    {
        $hider = $this->builder();
        $this->homes->build($hider);
        $this->travel(31)->minutes();
        $this->homes->enter($hider->fresh());
        $looker = $this->builder('granary');

        $granary = fn (Assert $page) => collect($page->toArray()['props']['regions'])->firstWhere('key', 'granary');
        $this->actingAs($looker->fresh()->user)->get(route('play'))->assertInertia(fn (Assert $page) => $this->assertSame(1, $granary($page)['animals']['count']));

        app(Movement::class)->walkTo($hider->fresh(), ...Geography::hub('market_square'));
        $this->assertNull($hider->fresh()->inside_home_at);
        $this->actingAs($looker->fresh()->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->has('homes', 1)
            ->where('homes.0.name', 'Hut')
            ->where('homes.0.owner.id', $hider->id));
    }

    public function test_a_home_guards_its_animal_against_arrest_and_burning(): void
    {
        $outlaw = $this->builder(attributes: ['wanted' => 1]);
        $home = $this->homes->build($outlaw);
        $home->forceFill(['level' => 3, 'ready_at' => null])->save();
        $this->homes->enter($outlaw->fresh());

        $this->assertSame(3, Homes::guard($outlaw->fresh()));

        // A Fort raises a burner's chance of being caught.
        $burner = $this->player(level: 3);
        $season = $this->season();
        $crime = Crime::create(['season_id' => $season->id, 'player_id' => $burner->id, 'kind' => CrimeKind::Burn, 'target_player_id' => $outlaw->id, 'settles_at' => now(), 'seed' => 1]);
        $this->assertEqualsWithDelta(0.3 + 3 * Homes::BURN_GUARD, app(Crimes::class)->catchChance($season, $crime), 0.001);

        // A burned home drops a level, and smokes out whoever hid in it.
        Homes::burned($outlaw->id);
        $this->assertSame(2, Home::sole()->level);
        $this->assertNull($outlaw->fresh()->inside_home_at);
    }

    public function test_a_bounty_hunter_at_a_fort_door_needs_more_luck(): void
    {
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $house->forceFill(['apples' => 500])->save();
        $this->hold($house, 'farmhouse');
        $outlaw = $this->builder();
        $outlaw->forceFill(['wanted' => 1])->save();
        $warrants = app(Warrants::class);
        $warrant = Warrant::query()->forceCreate(['season_id' => $this->season()->id, 'player_id' => $outlaw->id, 'faction_id' => $house->id, 'bounty' => 100, 'status' => 'open']);
        Home::create(['player_id' => $outlaw->id, 'region_id' => $outlaw->region_id, 'x' => $outlaw->pos_x, 'y' => $outlaw->pos_y, 'level' => 3]);
        $this->homes->enter($outlaw->fresh());
        $hunter = $this->builder(attributes: ['horseshoes' => 10]);

        // A roll that would catch an animal in the open misses one inside a Fort.
        $this->assertFalse($warrants->arrest($hunter->fresh(), $warrant, Warrants::ARREST_CHANCE - 0.05));
    }

    public function test_a_home_wears_a_skin_from_your_bag(): void
    {
        $player = $this->builder();
        $this->homes->build($player);
        $design = ItemDesign::create(['player_id' => $player->id, 'template' => ItemTemplate::Skin, 'name' => 'Violet Boards', 'colours' => ['#b39aff', '#16171d'], 'pattern' => 'stripes', 'status' => 'approved']);
        $skin = Item::create(['item_design_id' => $design->id, 'player_id' => $player->id, 'serial' => 1]);
        $hatDesign = ItemDesign::create(['player_id' => $player->id, 'template' => ItemTemplate::Hat, 'name' => 'Hat', 'colours' => ['#b39aff'], 'pattern' => 'plain', 'status' => 'approved']);
        $hat = Item::create(['item_design_id' => $hatDesign->id, 'player_id' => $player->id, 'serial' => 1]);

        try {
            $this->homes->dress($player->fresh(), $hat->id);
            $this->fail('A hat went on a home.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Only a home skin', $e->getMessage());
        }

        $this->homes->dress($player->fresh(), $skin->id);
        $this->assertSame(['name' => 'Violet Boards', 'colours' => ['#b39aff', '#16171d'], 'pattern' => 'stripes'], Homes::skin(Home::sole()->fresh()));

        // Given away, it no longer shows on the home.
        $skin->forceFill(['player_id' => $this->player(Species::Dog)->id])->save();
        $this->assertNull(Homes::skin(Home::sole()->fresh()));
    }
}
