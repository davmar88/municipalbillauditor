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
        $giver = $this->player();
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

    public function test_not_to_yourself_and_not_to_an_animal_that_blocked_you(): void
    {
        $giver = $this->player();
        $grump = $this->player();
        $hat = $this->item($giver);
        app(Messages::class)->block($grump, $giver);

        $this->actingAs($giver->user)->postJson(route('play.gift', $giver), ['item' => $hat->id])
            ->assertStatus(422)->assertJsonPath('message', "You can't give yourself a present.");
        $this->actingAs($giver->user)->postJson(route('play.gift', $grump), ['item' => $hat->id])
            ->assertStatus(422)->assertJsonPath('message', "{$grump->name} isn't taking anything from you.");

        $this->assertSame($giver->id, $hat->fresh()->player_id);
    }

    public function test_a_few_gifts_a_day(): void
    {
        $giver = $this->player();
        $friend = $this->player();
        $items = collect(range(1, Gifts::PER_DAY + 1))->map(fn (int $i) => $this->item($giver, "Hat {$i}"));

        foreach ($items->take(Gifts::PER_DAY) as $item) {
            $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $item->id])->assertOk();
        }

        $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $items->last()->id])
            ->assertStatus(422)->assertJsonPath('message', 'You have given '.Gifts::PER_DAY.' items today. You can give more tomorrow.');

        $this->travel(1)->days();
        $this->actingAs($giver->user)->postJson(route('play.gift', $friend), ['item' => $items->last()->id])->assertOk();
    }
}
