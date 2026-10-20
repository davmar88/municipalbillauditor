<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Gathering;
use App\Game\Geography;
use App\Game\Horseshoes;
use App\Game\LawTemplate;
use App\Game\Movement;
use App\Game\Players;
use App\Game\Powers;
use App\Game\Survival;
use App\Game\Wall;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Law;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Making an animal, the map, horseshoes and gathering.
 */
class StartAndMapTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    public function test_a_new_user_is_sent_to_make_their_animal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('play'))->assertRedirect(route('play.start'));
        $this->actingAs($user)->get(route('play.start'))->assertInertia(fn (Assert $page) => $page
            ->component('game/start')
            ->has('species', count(Species::playable())));
    }

    public function test_making_an_animal_needs_an_adult_and_a_playable_species(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('play.start.store'), ['name' => 'Sipho', 'species' => 'donkey', 'adult' => '1']);
        $this->assertNull($user->fresh()->player, 'Donkeys join in a later season.');

        $this->actingAs($user)->post(route('play.start.store'), ['name' => 'Sipho', 'species' => 'goat'])->assertSessionHasErrors('adult');
        $this->assertNull($user->fresh()->player);

        $this->actingAs($user)->post(route('play.start.store'), ['name' => 'Sipho', 'species' => 'goat', 'adult' => '1'])->assertRedirect(route('play'));

        $player = $user->fresh()->player;
        $this->assertSame('Sipho', $player->name);
        $this->assertSame(Species::Goat, $player->species);
        $this->assertSame(10, Horseshoes::current($player));
        $this->assertSame(100, $player->corn);
        // No newcomer protection: crime can find you from day one.
        $this->assertFalse($player->isShielded());
    }

    public function test_cows_llamas_and_zebras_can_join_each_with_its_own_knack(): void
    {
        foreach (['cow' => 'Daisy', 'llama' => 'Dolly', 'zebra' => 'Stripes'] as $species => $name) {
            $user = User::factory()->create();
            $this->actingAs($user)->post(route('play.start.store'), ['name' => $name, 'species' => $species, 'adult' => '1'])->assertRedirect(route('play'));
            $this->assertSame($species, $user->fresh()->player->species->value);
        }

        $this->assertSame(1.25, Species::Cow->gathering());
        $this->assertSame(1.3, Species::Zebra->pace());
        $this->assertSame(40, Survival::waterCap(Player::query()->where('name', 'Dolly')->sole()), 'Llamas carry twice the water.');
    }

    public function test_names_are_unique_in_the_valley(): void
    {
        $this->player(attributes: ['name' => 'Thandi']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('play.start.store'), ['name' => 'Thandi', 'species' => 'dog', 'adult' => '1']);

        $this->assertNull($user->fresh()->player);
    }

    public function test_the_map_shows_the_regions_and_farms_and_the_first_visit_earns_rosettes(): void
    {
        $player = $this->player(attributes: ['last_visit_on' => null]);

        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->component('game/map')
            ->has('regions', 127)
            ->where('player.name', $player->name));

        $this->assertSame(5, $player->fresh()->rosettes);

        $this->actingAs($player->user)->get(route('play'));
        $this->assertSame(5, $player->fresh()->rosettes, 'Only the first visit of the day pays.');
    }

    public function test_horseshoes_come_back_every_45_minutes_up_to_ten(): void
    {
        $player = $this->player();

        Horseshoes::spend($player, 3);
        $this->assertSame(7, Horseshoes::current($player->fresh()));

        $this->travel(46)->minutes();
        $this->assertSame(8, Horseshoes::current($player->fresh()));

        $this->travel(10)->hours();
        $this->assertSame(10, Horseshoes::current($player->fresh()));
    }

    public function test_extra_horseshoes_are_kept_until_spent(): void
    {
        $player = $this->player();
        Horseshoes::spend($player, 4);

        Horseshoes::bonus($player->fresh(), 50);
        $this->assertSame(56, Horseshoes::current($player->fresh()));

        $this->travel(10)->hours();
        $this->assertSame(56, Horseshoes::current($player->fresh()), 'None come back while over ten.');

        Horseshoes::spend($player->fresh(), 47);
        $this->assertSame(9, Horseshoes::current($player->fresh()));
        $this->travel(46)->minutes();
        $this->assertSame(10, Horseshoes::current($player->fresh()));
    }

    public function test_gathering_corn_pays_the_houses_grain_tax(): void
    {
        $farmer = $this->player(Species::Pig);
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $this->hold($house, 'farmhouse');
        Law::create(['season_id' => $this->season()->id, 'slot' => 1, 'template' => LawTemplate::GrainTax, 'value' => 10]);
        $this->stand($farmer, 'hayfield');

        $this->actingAs($farmer->user)->post(route('play.gather', $this->region('hayfield')))->assertRedirect();

        $this->assertSame(100 + Gathering::CORN - 2, $farmer->fresh()->corn);
        $this->assertSame(2, $house->fresh()->treasury);
        $this->assertSame(2, $this->season()->fresh()->tax_collected);
        $this->assertSame(9, Horseshoes::current($farmer->fresh()));
    }

    public function test_materials_gather_faster_on_your_own_land_and_dogs_gather_less(): void
    {
        $dog = $this->player(Species::Dog);
        $sheep = $this->player(Species::Sheep);
        $faction = $this->faction('Wool Co', $sheep);
        $this->hold($faction, 'sheepfold');
        $this->stand($sheep, 'sheepfold');
        $this->stand($dog, 'sheepfold');

        app(Gathering::class)->gather($sheep, $this->region('sheepfold'));
        app(Gathering::class)->gather($dog, $this->region('sheepfold'));

        $this->assertSame(7, $sheep->fresh()->material('wool'), '5 wool, half again on your own land.');
        $this->assertSame(3, $dog->fresh()->material('wool'), 'Dogs gather a quarter less.');
    }

    public function test_nothing_grows_at_the_farmhouse(): void
    {
        $player = $this->player();

        $this->actingAs($player->user)->post(route('play.gather', $this->region('farmhouse')))->assertRedirect();

        $this->assertSame(10, Horseshoes::current($player->fresh()));
    }

    public function test_animals_start_in_the_paddock_and_walk_to_any_point_at_their_pace(): void
    {
        $player = $this->player();
        $this->assertSame('paddock', $player->location()->key);
        [$fromX, $fromY] = Movement::position($player);
        $this->assertEqualsWithDelta(Geography::hub('paddock')[0], $fromX, 0.01);

        // Straight to the Farmhouse, past Market Square: it isn't next door, and that's fine now.
        [$x, $y] = Geography::hub('farmhouse');
        $this->actingAs($player->user)->post(route('play.walk-to'), ['x' => $x, 'y' => $y])->assertRedirect();

        $player->refresh();
        $pace = (int) config('game.walk.paces_per_minute');
        $minutes = hypot($x - $fromX, $y - $fromY) / $pace;
        $this->assertEqualsWithDelta($minutes * 60, $player->walk_started_at->diffInSeconds($player->walk_arrives_at), 1.5);
        $this->assertSame('paddock', $player->location()->key, 'Still in the Paddock as it sets off.');

        $this->travel((int) ceil($minutes * 60) + 1)->seconds();
        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('me.walk', null)
            ->where('me.pace', $pace));

        $this->assertSame('farmhouse', $player->fresh()->location()->key);
        $this->assertSame(10, Horseshoes::current($player->fresh()), 'Walking is free.');
    }

    public function test_an_animal_stands_in_the_region_it_is_passing_and_can_stop_there(): void
    {
        $player = $this->player();
        [$x, $y] = Geography::hub('farmhouse');
        app(Movement::class)->walkTo($player, $x, $y);
        $walk = $player->fresh();
        $length = $walk->walk_started_at->diffInSeconds($walk->walk_arrives_at);

        // Halfway there it is crossing Market Square.
        $this->travel((int) ($length * 0.55))->seconds();
        $this->assertSame('market_square', Players::lock($player->fresh())->location()->key);

        app(Movement::class)->stop($player->fresh());
        $this->travel(1)->hours();
        $stopped = $player->fresh();
        $this->assertNull($stopped->walk_arrives_at);
        $this->assertSame('market_square', $stopped->location()->key);
        $this->assertSame('market_square', Geography::regionAt(...Movement::position($stopped)));
    }

    public function test_walking_is_free_and_off_the_map_is_out_of_reach(): void
    {
        $player = $this->player();
        Horseshoes::spend($player, Horseshoes::current($player));

        app(Movement::class)->walk($player->fresh(), $this->region('market_square'));
        $this->travel(10)->minutes();

        $this->assertSame('market_square', Players::lock($player->fresh())->location()->key);
        $this->assertSame(0, Horseshoes::current($player->fresh()));

        $this->expectExceptionMessage("past the valley's edge");
        app(Movement::class)->walkTo($player->fresh(), 2900, 4100);
    }

    private function makeItem(Player $owner, ItemTemplate $template): Item
    {
        $design = ItemDesign::create([
            'player_id' => $owner->id,
            'template' => $template,
            'name' => $template->label(),
            'colours' => ['#b39aff', '#41d1ff'],
            'pattern' => 'plain',
            'edition' => null,
            'status' => 'approved',
        ]);

        return Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => 1]);
    }

    public function test_a_speedo_doubles_the_pace_even_mid_walk(): void
    {
        $player = $this->player();
        $speedo = $this->makeItem($player, ItemTemplate::Speedo);
        [$x, $y] = Geography::hub('farmhouse');
        app(Movement::class)->walkTo($player, $x, $y);
        $slow = $player->fresh()->walk_arrives_at;

        app(Powers::class)->use($player->fresh(), $speedo);

        $fast = $player->fresh();
        $this->assertSame(2 * (int) config('game.walk.paces_per_minute'), Movement::map($fast)['pace']);
        $this->assertTrue($fast->walk_arrives_at->lessThan($slow), 'The rest of the walk goes twice as fast.');
    }

    public function test_you_gather_where_you_stand(): void
    {
        $player = $this->player();

        $this->expectExceptionMessage('Walk to Hayfield first');
        app(Gathering::class)->gather($player, $this->region('hayfield'));
    }

    public function test_the_map_shows_where_animals_stand(): void
    {
        $player = $this->player();
        $this->stand($this->player(), 'henhouse');

        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('here', $this->region('paddock')->id)
            ->where('regions.11.key', 'henhouse')
            ->where('regions.11.animals.count', 1));
    }

    public function test_the_map_shows_what_you_and_the_animals_round_you_wear(): void
    {
        $player = $this->player();
        $other = $this->player(Species::Horse);
        $this->stand($other, 'henhouse');
        $wear = function (Player $owner, ItemTemplate $template, string $name): void {
            $design = ItemDesign::create(['player_id' => $owner->id, 'template' => $template, 'name' => $name, 'colours' => ['#b39aff'], 'pattern' => 'plain', 'status' => 'approved']);
            Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => 1]);
        };
        $wear($player, ItemTemplate::Scarf, 'Blue Scarf');
        $wear($other, ItemTemplate::Hat, 'Straw Hat');

        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('wearing.0.spot', 'scarf')
            ->where('wearing.0.name', 'Blue Scarf')
            ->where('regions.11.key', 'henhouse')
            ->where('regions.11.animals.sample.0.id', $other->id)
            ->where('regions.11.animals.sample.0.wearing.0.spot', 'hat')
            ->where('regions.11.animals.sample.0.wearing.0.name', 'Straw Hat'));
    }

    public function test_the_map_shows_the_laws_on_the_barn_wall(): void
    {
        $player = $this->player();
        Law::create(['season_id' => $this->season()->id, 'slot' => 1, 'template' => LawTemplate::Curfew, 'value' => 3]);

        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->has('laws', 1)
            ->where('laws.0.label', 'Curfew')
            ->where('laws.0.wording', 'No raids on House land for 3 hours after 22:00.'));
    }

    public function test_unrest_counts_each_law_and_a_house_that_keeps_all_its_tax(): void
    {
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $season = $this->season();

        $this->assertSame(0, Wall::unrest($season));

        $this->hold($house, 'farmhouse');
        $this->assertSame(15, Wall::unrest($season->fresh()), 'No rations law: the House keeps everything.');

        Law::create(['season_id' => $season->id, 'slot' => 1, 'template' => LawTemplate::GrainTax, 'value' => 15]);
        Law::create(['season_id' => $season->id, 'slot' => 2, 'template' => LawTemplate::MarketLevy, 'value' => 5]);
        Law::create(['season_id' => $season->id, 'slot' => 3, 'template' => LawTemplate::Rations, 'value' => 30, 'target' => 'pig']);

        $this->assertSame(15 + 10 + 10, Wall::unrest($season->fresh()));
    }

    public function test_a_player_without_horseshoes_cannot_gather(): void
    {
        $player = $this->player(attributes: ['horseshoes' => 0, 'horseshoes_at' => now()]);
        $this->stand($player, 'hayfield');

        $this->actingAs($player->user)
            ->post(route('play.gather', $this->region('hayfield')))
            ->assertRedirect();

        $this->assertSame(100, Player::find($player->id)->corn);
    }
}
