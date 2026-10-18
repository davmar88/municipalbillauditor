<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Chat;
use App\Game\Clock;
use App\Game\GameException;
use App\Game\Horseshoes;
use App\Game\LawTemplate;
use App\Game\Market;
use App\Models\AppleEntry;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Law;
use App\Models\Notice;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Shops pricing items in Apples, where the game keeps its share, and
 * Orchard membership: bought with Apples, with a free horseshoe refill a
 * day, a badge and an extra shelf.
 */
class AppleShopsAndOrchardTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->market = app(Market::class);
    }

    private function maker(): Player
    {
        return $this->player(Species::Sheep, level: 2, attributes: ['materials' => ['straw' => 20, 'wool' => 20, 'wood' => 20, 'clay' => 5, 'dye' => 5, 'stone' => 0, 'eggs' => 0]]);
    }

    private function item(Player $maker): Item
    {
        $design = $this->market->design($maker, ItemTemplate::Scarf, 'Violet Scarf', ['#b39aff', '#41d1ff'], 'stripes', null);
        $design->forceFill(['status' => 'approved'])->save();

        return $this->market->craft($maker->fresh(), $design->fresh());
    }

    private function member(Player $player): Player
    {
        $player->forceFill(['orchard_until' => now()->addDays(10)])->save();

        return $player->fresh();
    }

    public function test_an_apple_sale_pays_the_seller_85_percent_and_the_game_keeps_the_rest(): void
    {
        // The House's levy is for Corn sales only.
        $house = $this->faction('The House', $this->player());
        $this->hold($house, 'farmhouse');
        Law::create(['season_id' => $this->season()->id, 'slot' => 1, 'template' => LawTemplate::MarketLevy, 'value' => 5]);

        $maker = $this->maker();
        $item = $this->item($maker);

        $this->actingAs($maker->user)->post(route('play.list', $item), ['price' => 100, 'currency' => 'apples'])
            ->assertInertiaFlash('toast.message', 'On your shelves for 100 Apples.');
        $listing = $item->openListing()->firstOrFail();

        $buyer = $this->player(level: 2, attributes: ['apples' => 150, 'corn' => 0]);
        $this->actingAs($buyer->user)->get(route('play.market'))->assertInertia(fn (Assert $page) => $page
            ->where('listings.0.price', 100)
            ->where('listings.0.currency', 'apples'));
        $this->actingAs($buyer->user)->post(route('play.buy', $listing))
            ->assertInertiaFlash('toast.message', 'Bought for 100 Apples.');

        $this->assertSame(50, $buyer->fresh()->apples);
        $this->assertSame(85, $maker->fresh()->apples);
        $this->assertSame(0, $house->fresh()->treasury);
        $this->assertSame([0, 15], [$listing->fresh()->levy, $listing->fresh()->cut]);
        $this->assertSame($buyer->id, $item->fresh()->player_id);
        $this->assertSame(-100, AppleEntry::query()->where('reference', "listing:{$listing->id}:paid")->value('amount'));
        $this->assertSame(85, AppleEntry::query()->where('reference', "listing:{$listing->id}:sold")->value('amount'));
        $this->assertStringContainsString("You got 85 after the game's 15%.", Notice::query()->where('player_id', $maker->id)->where('kind', 'sale')->latest('id')->value('text'));

        // A resale pays the maker a royalty out of the seller's share.
        $resale = $this->market->list($buyer->fresh(), $item->fresh(), 200, 'apples');
        $this->market->buy($this->player(attributes: ['apples' => 300]), $resale);

        $this->assertSame(50 + 160, $buyer->fresh()->apples, 'The reseller gets 85% less the 5% royalty.');
        $this->assertSame(85 + 10, $maker->fresh()->apples);
        $this->assertSame(30, $resale->fresh()->cut);
    }

    public function test_the_game_keeps_its_share_however_the_price_rounds(): void
    {
        foreach ([5, 7, 19, 20, 99, 133, 1000] as $price) {
            foreach ([false, true] as $resale) {
                $split = Market::appleSplit($price, $resale);

                $this->assertSame($price, $split['seller'] + $split['royalty'] + $split['cut']);
                $this->assertGreaterThanOrEqual($price * 0.15, $split['cut'], "The game keeps at least 15% of {$price}.");
                $this->assertGreaterThan(0, $split['seller']);
            }
        }
    }

    public function test_apple_prices_stay_in_range_and_buyers_need_the_apples(): void
    {
        $maker = $this->maker();
        $item = $this->item($maker);

        $this->actingAs($maker->user)->post(route('play.list', $item), ['price' => 100, 'currency' => 'gold'])->assertSessionHasErrors('currency');

        try {
            $this->market->list($maker->fresh(), $item, 2, 'apples');
            $this->fail('A 2-Apple price was allowed.');
        } catch (GameException $e) {
            $this->assertSame('Price items between 5 and 1,000 Apples.', $e->getMessage());
        }

        $listing = $this->market->list($maker->fresh(), $item, 10, 'apples');
        $buyer = $this->player(attributes: ['apples' => 5, 'corn' => 5000]);

        $this->expectExceptionMessage('That costs 10 Apples. You have 5.');
        $this->market->buy($buyer, $listing);
    }

    public function test_orchard_members_get_a_seventh_shelf(): void
    {
        $maker = $this->maker();
        $design = ItemDesign::query()->findOrFail($this->item($maker)->item_design_id);
        $items = collect(range(2, 8))->map(fn (int $serial) => Item::create(['item_design_id' => $design->id, 'player_id' => $maker->id, 'serial' => $serial]));

        foreach ($items->take(6) as $item) {
            $this->market->list($maker->fresh(), $item, 20);
        }

        try {
            $this->market->list($maker->fresh(), $items[6], 20);
            $this->fail('A seventh shelf opened for a non-member.');
        } catch (GameException $e) {
            $this->assertSame('Your shop has 6 shelves and they are full. Orchard members get one more.', $e->getMessage());
        }

        $member = $this->member($maker);
        $this->market->list($member, $items[6], 20);

        $this->expectExceptionMessage('Your shop has 7 shelves and they are full.');
        $this->market->list($member, Item::create(['item_design_id' => $design->id, 'player_id' => $maker->id, 'serial' => 9]), 20);
    }

    public function test_joining_the_orchard_costs_60_apples_and_renewing_adds_a_month(): void
    {
        $this->freezeSecond();
        $player = $this->player(attributes: ['apples' => 130]);
        $first = Clock::local(now()->addDays(31))->format('j M');
        $second = Clock::local(now()->addDays(62))->format('j M');

        $this->actingAs($player->user)->post(route('play.orchard.join'))
            ->assertInertiaFlash('toast.message', "Welcome to the Orchard! Your membership runs until {$first}.");
        $this->assertSame(70, $player->fresh()->apples);
        $this->assertTrue($player->fresh()->orchard_until->equalTo(now()->addDays(31)));

        $this->actingAs($player->user)->post(route('play.orchard.join'))
            ->assertInertiaFlash('toast.message', "Your Orchard membership now runs until {$second}.");
        $this->assertSame(10, $player->fresh()->apples);
        $this->assertTrue($player->fresh()->orchard_until->equalTo(now()->addDays(62)));

        $this->actingAs($player->user)->post(route('play.orchard.join'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Orchard membership costs 60 Apples. You have 10.']);
        $this->assertSame(2, AppleEntry::query()->where('player_id', $player->id)->where('amount', -60)->count());

        $this->actingAs($player->fresh()->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->where('orchard.price', 60)
            ->where('orchard.until', $player->fresh()->orchard_until->toIso8601String())
            ->where('orchard.refillReady', true));
    }

    public function test_members_get_one_free_horseshoe_refill_a_day(): void
    {
        $this->travelTo(Clock::now()->setTime(12, 0));
        $outsider = $this->player(attributes: ['horseshoes' => 1, 'horseshoes_at' => now()]);

        $this->actingAs($outsider->user)->post(route('play.orchard.refill'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The free daily refill is for Orchard members.']);

        $member = $this->member($this->player(attributes: ['horseshoes' => 2, 'horseshoes_at' => now()]));

        $this->actingAs($member->user)->post(route('play.orchard.refill'))
            ->assertInertiaFlash('toast.message', 'Your horseshoes are full again. Your next free refill is ready tomorrow.');
        $this->assertSame(Horseshoes::max(), Horseshoes::current($member->fresh()));

        $member->fresh()->forceFill(['horseshoes' => 0, 'horseshoes_at' => now()])->save();
        $this->actingAs($member->user)->post(route('play.orchard.refill'))
            ->assertInertiaFlash('toast.message', "You've had today's free refill. The next one is ready tomorrow.");
        $this->assertSame(0, Horseshoes::current($member->fresh()));

        $this->travel(1)->days();
        $this->actingAs($member->user)->get(route('play.apples'))->assertInertia(fn (Assert $page) => $page
            ->where('player.freeRefill', true)
            ->where('orchard.refillReady', true));

        // The day's refill isn't spent on horseshoes that are already full.
        $this->assertSame(Horseshoes::max(), Horseshoes::current($member->fresh()));
        $this->actingAs($member->user)->post(route('play.orchard.refill'))
            ->assertInertiaFlash('toast.message', 'Your horseshoes are already full. Keep the refill for when you run low.');
        $this->assertNotSame(Clock::today(), $member->fresh()->orchard_refill_on->toDateString());
    }

    public function test_the_badge_shows_by_a_members_name_and_today_counts_apple_sales(): void
    {
        $maker = $this->member($this->maker());
        app(Chat::class)->post($maker, Chat::valley(), 'Fresh scarves on my shelves');
        $listing = $this->market->list($maker->fresh(), $this->item($maker), 40, 'apples');
        $this->market->buy($this->player(attributes: ['apples' => 40]), $listing);

        $reader = $this->player();
        $this->actingAs($reader->user)->get(route('play.chat', ['channel' => Chat::valley()]))->assertInertia(fn (Assert $page) => $page
            ->where('messages.0.from.name', $maker->name)
            ->where('messages.0.from.orchard', true));

        $this->actingAs($maker->user)->get(route('play.today'))->assertInertia(fn (Assert $page) => $page
            ->where('shop.capacity', 7)
            ->where('shop.applesToday', 34)
            ->where('shop.cornToday', 0)
            ->where('shop.recent.0.currency', 'apples'));
    }
}
