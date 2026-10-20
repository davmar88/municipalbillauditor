<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Market;
use App\Game\Rosettes;
use App\Game\ValleyBuilder;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Player;
use App\Models\Warrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Another animal's profile, opened from its name: who it is, its level,
 * skills and record, its shop and its collection, and nothing it holds.
 */
class AnimalProfileTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    public function test_a_profile_shows_the_animals_stats_shop_and_collection(): void
    {
        $viewer = $this->player();
        $bo = $this->player(Species::Dog, level: 3, attributes: ['wanted' => 1]);
        $this->faction('Red Hooves', $bo);
        $season = $this->season();

        Rosettes::award($bo, 10, 'Won the raid on Henhouse', "battle:1:{$bo->id}", $season);
        Rosettes::award($bo, 10, 'Won the raid on Quarry', "battle:2:{$bo->id}", $season);
        Rosettes::award($bo, 5, 'Exposed a secret edit', "expose:1:{$bo->id}", $season);
        Rosettes::award($bo, 15, 'Held the gate', "gate:2026-10-18:{$bo->id}", $season);

        $market = app(Market::class);
        $design = ItemDesign::create(['player_id' => $bo->id, 'template' => ItemTemplate::Hat, 'name' => 'Violet Hat', 'colours' => ['#b39aff', '#41d1ff'], 'pattern' => 'plain', 'status' => 'approved']);
        $hat = fn (int $serial) => Item::create(['item_design_id' => $design->id, 'player_id' => $bo->id, 'serial' => $serial]);
        $market->buy($this->player(attributes: ['corn' => 500]), $market->list($bo->fresh(), $hat(1), 50));
        $shelf = $market->list($bo->fresh(), $hat(2), 30, 'apples');
        $kept = $hat(3);

        (new Warrant)->forceFill(['season_id' => $season->id, 'player_id' => $bo->id, 'bounty' => 25, 'status' => 'open'])->save();
        $bo = $bo->fresh();

        $this->actingAs($viewer->user)->get(route('play.animal', $bo))->assertInertia(fn (Assert $page) => $page
            ->component('game/animal')
            ->where('animal.id', $bo->id)
            ->where('animal.name', $bo->name)
            ->where('animal.species', 'dog')
            ->where('animal.faction', 'Red Hooves')
            ->where('animal.factionRole', 'Leader')
            ->where('animal.level', $bo->level())
            ->where('animal.rosettes', $bo->rosettes)
            // The four above and the sale's 3.
            ->where('animal.seasonRosettes', 43)
            ->where('animal.inPoundUntil', null)
            ->where('animal.homeClosed', false)
            ->where('biography', "{$bo->name} the Dog. Leader of Red Hooves. Caught 1 secret edit. Won 2 battles. Sold 1 item. Known to the Night Watch.")
            ->where('skills.0.skill', 'market')
            ->where('skills.0.points', $bo->market_skill)
            ->where('skills.1.skill', 'creativity')
            ->where('skills.1.points', $bo->creativity_skill)
            ->where('record.battles', 2)
            ->where('record.gates', 1)
            ->where('record.arrests', 0)
            ->where('record.exposes', 1)
            ->where('record.missions', 0)
            ->where('record.sales', 1)
            ->where('record.designs', 1)
            ->where('record.caught', 1)
            ->where('bounty', 25)
            ->has('shop.listings', 1)
            ->where('shop.listings.0.id', $shelf->id)
            ->where('shop.listings.0.price', 30)
            ->where('shop.listings.0.currency', 'apples')
            ->where('shop.listings.0.design.name', 'Violet Hat')
            // What is on the shelves isn't counted twice in the collection.
            ->has('collection.items', 1)
            ->where('collection.items.0.id', $kept->id)
            ->where('collection.count', 1));
    }

    public function test_a_profile_never_shows_what_the_animal_holds_or_where_it_stands(): void
    {
        $viewer = $this->player();
        $bo = $this->player(attributes: ['corn' => 1234, 'apples' => 567, 'pineapples' => 89, 'horseshoes' => 7]);
        $this->stand($bo, 'quarry');

        $this->actingAs($viewer->user)->get(route('play.animal', $bo))->assertInertia(fn (Assert $page) => $page
            ->component('game/animal')
            // Without etc(), any key not named here fails the test.
            ->has('animal', fn (Assert $animal) => $animal->hasAll([
                'id', 'name', 'species', 'faction', 'colour', 'orchard', 'speciesLabel', 'role', 'talent', 'weakSpot',
                'factionRole', 'factionId', 'level', 'rosettes', 'levelFrom', 'nextLevelAt', 'seasonRosettes', 'joined', 'inPoundUntil', 'homeClosed',
            ]))
            ->has('record', fn (Assert $record) => $record->hasAll(['battles', 'gates', 'arrests', 'exposes', 'missions', 'sales', 'designs', 'caught']))
            ->where('bounty', null)
            ->has('shop.listings', 0)
            ->has('collection.items', 0));
    }

    public function test_your_own_name_opens_your_own_page(): void
    {
        $me = $this->player();

        $this->actingAs($me->user)->get(route('play.animal', $me))->assertRedirect(route('play.me'));
    }

    public function test_an_animal_in_another_valley_has_no_profile_here(): void
    {
        $viewer = $this->player();
        $home = $this->valley;
        $this->valley = app(ValleyBuilder::class)->build('Elsewhere');
        $stranger = $this->player();
        $this->valley = $home;

        $this->assertNotSame($viewer->valley_id, $stranger->valley_id);
        $this->actingAs($viewer->user)->get(route('play.animal', $stranger))->assertNotFound();
        $this->actingAs($viewer->user)->get(route('play.animal', Player::query()->max('id') + 1))->assertNotFound();
    }

    public function test_an_animal_wears_its_newest_unused_items_one_per_spot(): void
    {
        $viewer = $this->player();
        // A shop opens at level 2, for the copy on the shelf.
        $bo = $this->player(Species::Horse, level: 2);
        $item = function (ItemTemplate $template, string $name, string $pattern = 'plain') use ($bo): Item {
            $design = ItemDesign::create(['player_id' => $bo->id, 'template' => $template, 'name' => $name, 'colours' => ['#b39aff', '#41d1ff'], 'pattern' => $pattern, 'status' => 'approved']);

            return Item::create(['item_design_id' => $design->id, 'player_id' => $bo->id, 'serial' => 1]);
        };

        $item(ItemTemplate::Hat, 'Old Hat');
        $item(ItemTemplate::Hat, 'New Hat', 'stars');
        // A used-up copy and one on the shop shelf aren't worn.
        $item(ItemTemplate::Scarf, 'Spent Scarf')->forceFill(['used_at' => now()])->save();
        app(Market::class)->list($bo->fresh(), $item(ItemTemplate::Cloak, 'Shelf Cloak'), 40);
        $item(ItemTemplate::Shoes, 'Iron Shoes');
        // One thing rides on the back, a banner before a basket.
        $item(ItemTemplate::Basket, 'Picnic');
        $item(ItemTemplate::Banner, 'Red Banner');
        $item(ItemTemplate::Tonic, 'Not Worn');

        $expected = [
            ['spot' => 'hat', 'template' => 'hat', 'name' => 'New Hat', 'colours' => ['#b39aff', '#41d1ff'], 'pattern' => 'stars'],
            ['spot' => 'shoes', 'template' => 'shoes', 'name' => 'Iron Shoes', 'colours' => ['#b39aff', '#41d1ff'], 'pattern' => 'plain'],
            ['spot' => 'carried', 'template' => 'banner', 'name' => 'Red Banner', 'colours' => ['#b39aff', '#41d1ff'], 'pattern' => 'plain'],
        ];

        $this->actingAs($viewer->user)->get(route('play.animal', $bo))->assertInertia(fn (Assert $page) => $page
            ->component('game/animal')
            ->where('wearing', $expected));
        $this->actingAs($bo->user)->get(route('play.me'))->assertInertia(fn (Assert $page) => $page
            ->component('game/me')
            ->where('wearing', $expected));
    }
}
