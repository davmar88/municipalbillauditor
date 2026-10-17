<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\GameException;
use App\Game\LawTemplate;
use App\Game\Market;
use App\Models\ItemDesign;
use App\Models\Law;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Workshop and the shops.
 */
class MarketTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->market = app(Market::class);
    }

    private function maker(Species $species = Species::Sheep): Player
    {
        return $this->player($species, level: 2, attributes: ['materials' => ['straw' => 20, 'wool' => 20, 'wood' => 20, 'clay' => 5, 'dye' => 5, 'stone' => 0, 'eggs' => 0]]);
    }

    private function design(Player $maker, ?int $edition = null): ItemDesign
    {
        $design = $this->market->design($maker, ItemTemplate::Scarf, 'Violet Scarf', ['#b39aff', '#41d1ff'], 'stripes', $edition);
        $design->forceFill(['status' => 'approved'])->save();

        return $design;
    }

    public function test_a_new_makers_designs_wait_for_a_check_before_copies_are_made(): void
    {
        $maker = $this->maker();
        $design = $this->market->design($maker, ItemTemplate::Hat, 'Straw Crown', ['#ffd33d', '#16171d'], 'plain', 10);

        $this->assertSame('pending', $design->status);
        $this->expectExceptionMessage('quick check');
        $this->market->craft($maker, $design);
    }

    public function test_staff_approve_designs(): void
    {
        $design = $this->market->design($this->maker(), ItemTemplate::Hat, 'Straw Crown', ['#ffd33d', '#16171d'], 'plain', 10);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('staff.designs'))->assertInertia(fn (Assert $page) => $page->component('staff/designs')->has('designs', 1));
        $this->actingAs($staff)->post(route('staff.designs.approve', $design))->assertRedirect();

        $this->assertSame('approved', $design->fresh()->status);
    }

    public function test_making_a_copy_uses_materials_and_numbers_it(): void
    {
        $maker = $this->maker();
        $design = $this->design($maker, 50);

        $first = $this->market->craft($maker, $design);
        $second = $this->market->craft($maker->fresh(), $design->fresh());

        $this->assertSame([1, 2], [$first->serial, $second->serial]);
        $this->assertSame(12, $maker->fresh()->material('wool'));
        $this->assertSame(3, $maker->fresh()->material('dye'));
    }

    public function test_horses_use_half_the_materials(): void
    {
        $horse = $this->maker(Species::Horse);

        $this->market->craft($horse, $this->design($horse));

        $this->assertSame(18, $horse->fresh()->material('wool'));
    }

    public function test_a_limited_edition_sells_out(): void
    {
        $maker = $this->maker();
        $design = $this->design($maker, 10);
        $design->forceFill(['made' => 10])->save();

        $this->expectException(GameException::class);
        $this->market->craft($maker, $design->fresh());
    }

    public function test_buying_pays_the_levy_to_the_house_and_resales_pay_the_maker(): void
    {
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $this->hold($house, 'farmhouse');
        Law::create(['season_id' => $this->season()->id, 'slot' => 1, 'template' => LawTemplate::MarketLevy, 'value' => 5]);

        $maker = $this->maker();
        $item = $this->market->craft($maker, $this->design($maker));
        $listing = $this->market->list($maker->fresh(), $item, 100);

        $buyer = $this->player(level: 2, attributes: ['corn' => 300]);
        $this->actingAs($buyer->user)->post(route('play.buy', $listing))->assertRedirect();

        $this->assertSame(195, $buyer->fresh()->corn, 'Price plus the 5% levy.');
        $this->assertSame(200, $maker->fresh()->corn);
        $this->assertSame(5, $house->fresh()->treasury);
        $this->assertSame($buyer->id, $item->fresh()->player_id);
        $this->assertSame(3, $maker->fresh()->rosettes - 50);

        $resale = $this->market->list($buyer->fresh(), $item->fresh(), 200);
        $this->market->buy($this->player(attributes: ['corn' => 500]), $resale);

        $this->assertSame(195 + 190, $buyer->fresh()->corn, 'The reseller gets the price less the 5% royalty.');
        $this->assertSame(210, $maker->fresh()->corn, 'The maker earns the royalty.');
    }

    public function test_a_trade_ban_stops_the_banned_faction_trading(): void
    {
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $this->hold($house, 'farmhouse');
        $banned = $this->faction('Banned', $this->player(level: 2, attributes: ['corn' => 500]));
        Law::create(['season_id' => $this->season()->id, 'slot' => 1, 'template' => LawTemplate::TradeBan, 'target' => (string) $banned->id]);

        $maker = $this->maker();
        $listing = $this->market->list($maker, $this->market->craft($maker, $this->design($maker)), 50);

        $this->expectExceptionMessage('Trade Ban');
        $this->market->buy($banned->members()->first(), $listing);
    }

    public function test_a_closed_shop_cannot_sell(): void
    {
        $maker = $this->maker();
        $listing = $this->market->list($maker, $this->market->craft($maker, $this->design($maker)), 50);
        $maker->forceFill(['home_closed_until' => now()->addHours(5)])->save();

        $this->expectExceptionMessage('closed');
        $this->market->buy($this->player(), $listing);
    }

    public function test_a_shop_has_six_shelves(): void
    {
        $maker = $this->maker();
        $maker->forceFill(['materials' => [...$maker->materials, 'wool' => 100, 'dye' => 20]])->save();
        $design = $this->design($maker);

        for ($i = 0; $i < 6; $i++) {
            $this->market->list($maker->fresh(), $this->market->craft($maker->fresh(), $design->fresh()), 20);
        }

        $maker->forceFill(['horseshoes' => 10, 'horseshoes_at' => now()])->save();
        $this->expectExceptionMessage('shelves');
        $this->market->list($maker->fresh(), $this->market->craft($maker->fresh(), $design->fresh()), 20);
    }

    public function test_the_market_page_lists_what_is_for_sale(): void
    {
        $maker = $this->maker();
        $this->market->list($maker, $this->market->craft($maker, $this->design($maker)), 60);

        $this->actingAs($this->player()->user)->get(route('play.market'))->assertInertia(fn (Assert $page) => $page
            ->component('game/market')
            ->has('listings', 1)
            ->where('listings.0.price', 60)
            ->where('listings.0.design.name', 'Violet Scarf'));
    }
}
