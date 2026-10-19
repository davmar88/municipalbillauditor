<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Gifts;
use App\Game\Market;
use App\Game\Messages;
use App\Models\Gift;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Giving another animal an item from your bag, from the map's animal menu.
 */
class GiftsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    private function item(Player $owner, string $name = 'Straw Hat', ItemTemplate $template = ItemTemplate::Hat): Item
    {
        $design = ItemDesign::create(['player_id' => $owner->id, 'template' => $template, 'name' => $name, 'colours' => ['#b39aff'], 'pattern' => 'plain', 'status' => 'approved']);

        return Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => 1]);
    }

    public function test_an_animal_gives_another_an_item_from_its_bag(): void
    {
        $giver = $this->player(level: 2);
        $friend = $this->player(Species::Horse);
        $hat = $this->item($giver);

        $this->actingAs($giver->user)->getJson(route('play.gifts'))->assertOk()
            ->assertJsonPath('items.0.id', $hat->id)
            ->assertJsonPath('items.0.name', 'Straw Hat')
            ->assertJsonPath('left', Gifts::PER_DAY);

        $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $hat->id])->assertOk()
            ->assertJsonPath('message', "You gave {$friend->name} Straw Hat.")
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('left', Gifts::PER_DAY - 1);

        $this->assertSame($friend->id, $hat->fresh()->player_id);
        $this->assertSame(1, Gift::query()->where('from_player_id', $giver->id)->where('to_player_id', $friend->id)->count());
        $notice = Notice::query()->where('player_id', $friend->id)->sole();
        $this->assertSame('gift', $notice->kind);
        $this->assertStringContainsString("{$giver->name} gave you Straw Hat", $notice->text);
    }

    public function test_only_your_own_unused_items_off_the_shelf_can_go(): void
    {
        // A shop opens at level 2, for the copy on the shelf.
        $giver = $this->player(level: 2);
        $friend = $this->player();
        $stranger = $this->player();
        $theirs = $this->item($stranger, 'Not Mine');
        $spent = $this->item($giver, 'Spent Scarf', ItemTemplate::Scarf);
        $spent->forceFill(['used_at' => now()])->save();
        $shelved = $this->item($giver, 'Shelf Cloak', ItemTemplate::Cloak);
        app(Market::class)->list($giver->fresh(), $shelved, 40);

        foreach ([$theirs, $spent, $shelved] as $item) {
            $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $item->id])->assertStatus(422);
        }

        $this->actingAs($giver->user)->getJson(route('play.gifts'))->assertJsonCount(0, 'items');
        $this->assertSame($stranger->id, $theirs->fresh()->player_id);
        $this->assertSame($giver->id, $shelved->fresh()->player_id);
        $this->assertSame(0, Gift::count());
    }

    public function test_not_to_yourself_nor_across_a_block_nor_into_the_pound(): void
    {
        $giver = $this->player(level: 2);
        $grump = $this->player();
        $pest = $this->player();
        $jailbird = $this->player(attributes: ['pound_until' => now()->addHour()]);
        $hat = $this->item($giver);
        app(Messages::class)->block($grump, $giver);
        app(Messages::class)->block($giver, $pest);

        $this->actingAs($giver->user)->postJson(route('play.gift', $giver), ['item' => $hat->id])
            ->assertStatus(422)->assertJsonPath('message', "You can't give yourself a present.");
        $this->actingAs($giver->user)->postJson(route('play.gift', $grump), ['item' => $hat->id])
            ->assertStatus(422)->assertJsonPath('message', "{$grump->name} isn't taking anything from you.");
        $this->actingAs($giver->user)->postJson(route('play.gift', $pest), ['item' => $hat->id])
            ->assertStatus(422)->assertJsonPath('message', "You blocked {$pest->name}. Unblock them to give them something.");
        $this->actingAs($giver->user)->postJson(route('play.gift', $jailbird), ['item' => $hat->id])->assertStatus(422);

        $this->assertSame($giver->id, $hat->fresh()->player_id);
    }

    public function test_new_animals_and_animals_in_the_pound_cant_give(): void
    {
        $newcomer = $this->player();
        $jailbird = $this->player(level: 2, attributes: ['pound_until' => now()->addHour()]);
        $friend = $this->player();

        foreach ([$newcomer, $jailbird] as $giver) {
            $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $this->item($giver)->id])->assertStatus(422);
        }

        $this->assertSame(0, Gift::count());
    }

    public function test_an_item_bought_with_apples_waits_before_it_can_be_given(): void
    {
        $seller = $this->player(level: 2);
        $buyer = $this->player(level: 2, attributes: ['apples' => 500]);
        $friend = $this->player();
        $hat = $this->item($seller);
        $listing = app(Market::class)->list($seller->fresh(), $hat, 20, 'apples');
        app(Market::class)->buy($buyer->fresh(), $listing);

        $this->actingAs($buyer->user)->postJson(route('play.gift', $friend), ['item' => $hat->id])
            ->assertStatus(422)->assertJsonPath('message', "You bought Straw Hat with Apples in the last 14 days, so it can't be given away yet.");

        $this->travel(15)->days();
        $this->actingAs($buyer->user)->postJson(route('play.gift', $friend), ['item' => $hat->id])->assertOk();
    }

    public function test_a_given_item_left_on_a_shelf_cant_be_bought(): void
    {
        $seller = $this->player(level: 2);
        $buyer = $this->player(attributes: ['corn' => 500]);
        $hat = $this->item($seller);
        $listing = app(Market::class)->list($seller->fresh(), $hat, 20);
        // It changed hands after it was shelved.
        $hat->forceFill(['player_id' => $buyer->id])->save();

        $this->expectExceptionMessage('That item is no longer for sale.');

        try {
            app(Market::class)->buy($this->player(attributes: ['corn' => 500]), $listing);
        } finally {
            $this->assertNull(Listing::find($listing->id));
        }
    }

    public function test_a_few_gifts_a_day_each_way(): void
    {
        $giver = $this->player(level: 2);
        $friend = $this->player();
        $items = collect(range(1, Gifts::PER_DAY + 1))->map(fn (int $i) => $this->item($giver, "Hat {$i}"));

        foreach ($items->take(Gifts::PER_DAY) as $item) {
            $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $item->id])->assertOk();
        }

        $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $items->last()->id])
            ->assertStatus(422)->assertJsonPath('message', 'You have given '.Gifts::PER_DAY.' items today. You can give more tomorrow.');

        $this->travel(1)->days();
        $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $items->last()->id])->assertOk();

        // Nobody can be handed more than ten a day, from however many friends.
        $this->travel(1)->days();
        foreach (range(1, Gifts::RECEIVE_PER_DAY) as $i) {
            $giverN = $this->player(level: 2);
            $this->actingAs($giverN->user)->postJson(route('play.gift', $friend), ['item' => $this->item($giverN, "Gift {$i}")->id])->assertOk();
        }
        $late = $this->player(level: 2);
        $this->actingAs($late->user)->postJson(route('play.gift', $friend), ['item' => $this->item($late, 'Late')->id])
            ->assertStatus(422)->assertJsonPath('message', "{$friend->name} has been given ".Gifts::RECEIVE_PER_DAY.' items today. Try again tomorrow.');
    }
}
