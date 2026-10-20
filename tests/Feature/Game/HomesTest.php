<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Apples;
use App\Game\Crimes;
use App\Game\GameException;
use App\Game\Geography;
use App\Game\Homes;
use App\Game\Movement;
use App\Game\Powers;
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

        // And on, without end: a Keep, a Castle, Castle 2...
        $this->travel(181)->minutes();
        $this->assertSame(['Keep', 'Castle', 'Castle 2', 'Castle 7'], [Homes::name(4), Homes::name(5), Homes::name(6), Homes::name(11)]);
        $this->assertSame(450, Homes::level(4)['corn']);
        $this->assertSame(['stone' => 55, 'wood' => 35, 'iron' => 6], Homes::level(4)['materials']);
        $this->assertSame(24 * 60, Homes::level(40)['minutes']);
        $player->fresh()->forceFill(['corn' => 5000, 'materials' => ['stone' => 100, 'wood' => 100, 'iron' => 10]])->save();
        $this->assertSame(4, $this->homes->upgrade($player->fresh())->level);
    }

    public function test_however_high_a_home_a_hunter_keeps_a_chance(): void
    {
        $this->assertEqualsWithDelta(0.3, Homes::arrestCut(3, 0.55), 0.001);
        $this->assertEqualsWithDelta(0.45, Homes::arrestCut(40, 0.55), 0.001);
        $this->assertEqualsWithDelta(Homes::ARREST_FLOOR, 0.55 - Homes::arrestCut(40, 0.55), 0.001);
    }

    public function test_faction_mates_lend_a_hand_and_a_mortar_raises_a_level_at_once(): void
    {
        $owner = $this->builder();
        $mate = $this->builder(attributes: ['horseshoes' => 10]);
        $stranger = $this->builder();
        $this->faction('Red Hooves', $owner, $mate);
        $home = $this->homes->build($owner->fresh());
        $before = $home->ready_at->diffInSeconds(now(), true);

        try {
            $this->homes->help($stranger->fresh(), $home);
            $this->fail('A stranger helped.');
        } catch (GameException $e) {
            $this->assertStringContainsString('faction mates', $e->getMessage());
        }

        $this->homes->help($mate->fresh(), $home);
        $this->assertEqualsWithDelta($before * 2 / 3, $home->fresh()->ready_at->diffInSeconds(now(), true), 2);
        $this->assertSame(9, $mate->fresh()->horseshoes);

        try {
            $this->homes->help($mate->fresh(), $home);
            $this->fail('Helped the same level twice.');
        } catch (GameException $e) {
            $this->assertStringContainsString('already helping', $e->getMessage());
        }

        // Builder's Mortar: the next level at once, for its materials and no Corn.
        $this->travel(31)->minutes();
        $design = ItemDesign::create(['player_id' => $owner->id, 'template' => ItemTemplate::Mortar, 'name' => 'Quick Lime', 'colours' => ['#ffffff'], 'pattern' => 'plain', 'status' => 'approved']);
        $mortar = Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => 1]);
        $corn = $owner->fresh()->corn;
        app(Powers::class)->use($owner->fresh(), $mortar);

        $this->assertSame(2, $home->fresh()->level);
        $this->assertFalse($home->fresh()->building());
        $this->assertSame($corn, $owner->fresh()->corn);
        $this->assertNotNull($mortar->fresh()->used_at);
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

    public function test_the_skin_shop_sells_corn_skins_then_apple_skins_in_order(): void
    {
        $player = $this->builder(attributes: ['corn' => 2000]);
        $this->homes->build($player);

        foreach ([1, 2, 3, 4] as $level) {
            $this->homes->buySkin($player->fresh());
        }

        $home = Home::sole();
        $this->assertSame(4, $home->skin_level);
        $spent = array_sum(array_map(fn (int $level) => Homes::SKIN_SHOP[$level]['corn'], [1, 2, 3, 4]));
        $this->assertSame(2000 - Homes::LEVELS[1]['corn'] - $spent, $player->fresh()->corn);
        $this->assertSame('Fieldstone', Homes::skin($home)['name']);

        // Past Fieldstone the shop wants Apples.
        try {
            $this->homes->buySkin($player->fresh());
            $this->fail('Red Brick costs Apples.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Apples', $e->getMessage());
        }

        Apples::award($player->fresh(), 10, 'Test Apples', 'test:skin');
        $this->homes->buySkin($player->fresh());
        $this->assertSame(5, $home->fresh()->skin_level);
        $this->assertSame(0, $player->fresh()->apples);
    }

    public function test_a_shop_skin_guards_against_burners_and_hunters(): void
    {
        $outlaw = $this->builder();
        $home = $this->homes->build($outlaw);
        $home->forceFill(['level' => 1, 'ready_at' => null, 'skin_level' => 8])->save();

        $burner = $this->player(level: 3);
        $crime = Crime::create(['season_id' => $this->season()->id, 'player_id' => $burner->id, 'kind' => CrimeKind::Burn, 'target_player_id' => $outlaw->id, 'settles_at' => now(), 'seed' => 1]);
        $this->assertEqualsWithDelta(0.3 + Homes::BURN_GUARD + Homes::SKIN_SHOP[8]['defence'], app(Crimes::class)->catchChance($this->season(), $crime), 0.001);

        // Only inside does it guard against a bounty hunter.
        $this->assertSame(0.0, Homes::armour($outlaw->fresh()));
        $this->homes->enter($outlaw->fresh());
        $this->assertSame(Homes::SKIN_SHOP[8]['defence'], Homes::armour($outlaw->fresh()));

        $this->actingAs($outlaw->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('home.skinLevel', 8)
            ->has('home.skinShop', count(Homes::SKIN_SHOP))
            ->where('homes.0.skin.name', 'Gilded Keep'));
    }

    public function test_the_map_lets_you_set_another_animals_home_alight_even_a_mates(): void
    {
        $owner = $this->builder();
        $this->homes->build($owner);
        $burner = $this->player(level: 3, attributes: ['horseshoes' => 10]);
        $this->faction('Mates', $owner, $burner);

        $this->actingAs($burner->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('burn.level', (int) config('game.levels.crime'))
            ->where('burn.usedToday', false)
            ->where('homes.0.factionName', 'Mates')
            ->where('homes.0.mine', false));

        $this->actingAs($burner->user)->post(route('play.crime'), ['kind' => 'burn', 'player_id' => $owner->id])->assertRedirect();
        $this->assertSame($owner->id, Crime::sole()->target_player_id);

        $this->actingAs($burner->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('burn.usedToday', true)
            ->where('homes.0.fire', 'burning'));
    }
}
