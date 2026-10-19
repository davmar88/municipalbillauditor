<?php

namespace Tests\Feature\Game;

use App\Enums\BuildingKind;
use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\GameException;
use App\Game\Gathering;
use App\Game\Horseshoes;
use App\Game\Market;
use App\Game\Powers;
use App\Game\Rosettes;
use App\Game\Wardrobe;
use App\Models\Building;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Workshop's power recipes: who may design them, and what using a
 * copy does in gathering, raids, crime, the pound, Rosettes and the House.
 */
class RecipesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Powers $powers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->powers = app(Powers::class);
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    private function item(Player $owner, ItemTemplate $template): Item
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
        $design->increment('made');

        return Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => 1]);
    }

    public function test_stronger_recipes_open_up_as_creativity_grows(): void
    {
        $maker = $this->player();
        $market = app(Market::class);

        try {
            $market->design($maker, ItemTemplate::Cloak, 'Midnight', ['#16171d', '#b39aff'], 'plain', 50);
            $this->fail('A Night Cloak needs Creativity level 2.');
        } catch (GameException $e) {
            $this->assertStringContainsString('from Creativity level 2', $e->getMessage());
        }

        $maker->forceFill(['creativity_skill' => 30])->save();
        $design = $market->design($maker->fresh(), ItemTemplate::Cloak, 'Midnight', ['#16171d', '#b39aff'], 'plain', 50);

        $this->assertSame(ItemTemplate::Cloak, $design->template);
        $this->assertSame('Your next crime is half as likely to be caught.', $design->toCard()['power']);
    }

    public function test_looks_have_no_power_and_a_used_item_is_gone(): void
    {
        $player = $this->player();

        try {
            $this->powers->use($player, $this->item($player, ItemTemplate::Hat));
            $this->fail('A hat has no power.');
        } catch (GameException $e) {
            $this->assertStringContainsString('no power to use', $e->getMessage());
        }

        $gloves = $this->item($player, ItemTemplate::Gloves);
        $this->actingAs($player->user)->post(route('play.use', $gloves))->assertRedirect();

        $this->assertNotNull($gloves->fresh()->used_at);
        $this->assertFalse($player->fresh()->items()->whereKey($gloves->id)->exists(), 'Used items leave your things.');

        $this->expectExceptionMessage("isn't yours to use");
        $this->powers->use($player->fresh(), $gloves->fresh());
    }

    public function test_an_item_on_a_shelf_must_come_down_before_use(): void
    {
        $player = $this->player(level: 2);
        $gloves = $this->item($player, ItemTemplate::Gloves);
        app(Market::class)->list($player, $gloves, 40);

        $this->expectExceptionMessage('Take it off your shelves');
        $this->powers->use($player, $gloves);
    }

    public function test_gloves_help_the_next_five_gathers(): void
    {
        $player = $this->player();
        $this->stand($player, 'hayfield');
        $this->powers->use($player, $this->item($player, ItemTemplate::Gloves));

        foreach (range(1, 6) as $trip) {
            app(Gathering::class)->gather($player->fresh(), $this->region('hayfield'));
        }

        $this->assertSame(100 + 5 * 30 + 20, $player->fresh()->corn, 'Five gathers of 30, then 20 again.');
    }

    public function test_a_fruit_pie_doubles_five_gathers_and_a_veggie_stew_gives_horseshoes(): void
    {
        $player = $this->player();
        $this->stand($player, 'hayfield');
        $this->powers->use($player, $this->item($player, ItemTemplate::Pie));

        foreach (range(1, 6) as $trip) {
            app(Gathering::class)->gather($player->fresh(), $this->region('hayfield'));
        }

        $this->assertSame(100 + 5 * 40 + 20, $player->fresh()->corn, 'Five gathers of 40, then 20 again.');

        // Six horseshoes spent; the stew gives three back.
        $this->powers->use($player->fresh(), $this->item($player, ItemTemplate::Stew));
        $this->assertSame(10 - 6 + 3, Horseshoes::current($player->fresh()));
    }

    public function test_a_gold_crown_is_worn_instead_of_a_hat(): void
    {
        $player = $this->player();
        $this->item($player, ItemTemplate::Hat);
        $this->item($player, ItemTemplate::Crown);

        $this->assertSame('crown', Wardrobe::worn($player)[0]['template']);
    }

    public function test_a_feast_basket_helps_every_member_gather(): void
    {
        $cook = $this->player();
        $farmer = $this->player();
        $this->faction('Feasters', $cook, $farmer);
        $this->stand($farmer, 'hayfield');

        $this->powers->use($cook->fresh(), $this->item($cook, ItemTemplate::Basket));
        app(Gathering::class)->gather($farmer->fresh(), $this->region('hayfield'));

        $this->assertSame(125, $farmer->fresh()->corn);
    }

    public function test_iron_shoes_make_one_commitment_count_more(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $this->faction('Red Hooves', ...$red);
        $this->powers->use($red[0], $this->item($red[0], ItemTemplate::Shoes));

        $attack = app(Battles::class)->launch($red[0]->fresh(), $this->region('henhouse'), 2);
        app(Battles::class)->commit($red[1], $attack, 2);

        $boosts = $attack->commitments()->orderBy('id')->pluck('boost')->map(fn ($boost) => (float) $boost)->all();
        $this->assertSame([1.33, 1.0], $boosts);
    }

    public function test_a_war_banner_and_a_thorn_hedge_count_in_battle(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Bannermen', $leader);
        $this->hold($faction, 'granary');

        try {
            $this->powers->use($leader, $this->item($leader, ItemTemplate::Hedge));
            $this->fail("The Paddock isn't the faction's land.");
        } catch (GameException $e) {
            $this->assertStringContainsString('walk to a region it holds', $e->getMessage());
        }

        $this->stand($leader, 'granary');
        $this->powers->use($leader->fresh(), $this->item($leader, ItemTemplate::Hedge));
        $this->powers->use($leader->fresh(), $this->item($leader, ItemTemplate::Banner));

        $this->assertSame(Powers::HEDGE, Powers::defence($this->region('granary')));
        $this->assertSame(1.0, Powers::defence($this->region('henhouse')));
        $this->assertSame(Powers::BANNER, Powers::attack($faction->id));

        $this->travel(13)->hours();
        $this->assertSame(1.0, Powers::attack($faction->id), 'The banner comes down after 12 hours.');
        $this->assertSame(Powers::HEDGE, Powers::defence($this->region('granary')), 'The hedge lasts a day.');
    }

    public function test_a_night_cloak_and_a_watch_bell_change_the_odds_of_being_caught(): void
    {
        $wolf = $this->player(level: 3);
        $guard = $this->player();
        $faction = $this->faction('Vault', $guard);
        $crimes = app(Crimes::class);
        $season = $this->season();

        $this->powers->use($wolf, $this->item($wolf, ItemTemplate::Cloak));
        $cloaked = $crimes->commit($wolf->fresh(), CrimeKind::Loot, $faction);
        $this->assertTrue($cloaked->cloaked);
        $this->assertEqualsWithDelta(0.15, $crimes->catchChance($season, $cloaked), 0.0001);

        $this->powers->use($guard->fresh(), $this->item($guard, ItemTemplate::Bell));
        $this->assertEqualsWithDelta(0.275, $crimes->catchChance($season, $cloaked->fresh()), 0.0001, '0.3 + 0.25 with the bell, halved by the cloak.');
    }

    public function test_willow_tonic_walks_you_out_of_the_pound(): void
    {
        $player = $this->player(attributes: ['pound_until' => now()->addHours(5)]);

        try {
            $this->powers->use($player, $this->item($player, ItemTemplate::Gloves));
            $this->fail('Only a tonic works in the pound.');
        } catch (GameException $e) {
            $this->assertStringContainsString('in the pound', $e->getMessage());
        }

        $this->powers->use($player, $this->item($player, ItemTemplate::Tonic));
        $this->assertFalse($player->fresh()->inPound());

        $this->expectExceptionMessage("You're not in the pound");
        $this->powers->use($player->fresh(), $this->item($player, ItemTemplate::Tonic));
    }

    public function test_a_prize_ribbon_doubles_rosettes_for_three_hours(): void
    {
        $player = $this->player(attributes: ['rosettes' => 0]);
        $this->powers->use($player, $this->item($player, ItemTemplate::Ribbon));

        Rosettes::award($player->fresh(), 5, 'Test', 'ribbon:1');
        $this->assertSame(10, $player->fresh()->rosettes);

        $this->travel(4)->hours();
        Rosettes::award($player->fresh(), 5, 'Test', 'ribbon:2');
        $this->assertSame(15, $player->fresh()->rosettes);
    }

    public function test_a_masons_trowel_finishes_a_building_going_up(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Masons', $leader);
        $this->hold($faction, 'granary');
        $this->stand($leader, 'granary');
        $den = Building::create([
            'faction_id' => $faction->id,
            'region_id' => $this->region('granary')->id,
            'kind' => BuildingKind::Den,
            'level' => 1,
            'ready_at' => now()->addHour(),
        ]);

        $this->powers->use($leader->fresh(), $this->item($leader, ItemTemplate::Trowel));

        $this->assertTrue($den->fresh()->isReady());
    }

    public function test_only_the_house_can_use_a_golden_sheaf(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Ploughmen', $leader);

        try {
            $this->powers->use($leader, $this->item($leader, ItemTemplate::Sheaf));
            $this->fail('Not the House yet.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Only the House', $e->getMessage());
        }

        $this->hold($faction, 'farmhouse');
        $this->powers->use($leader->fresh(), $this->item($leader, ItemTemplate::Sheaf));

        $this->assertSame(1, $this->season()->fresh()->bonus_law_changes);
    }

    public function test_the_screens_show_powers_and_what_is_working(): void
    {
        $player = $this->player();
        $this->item($player, ItemTemplate::Shoes);
        $this->powers->use($player, $this->item($player, ItemTemplate::Gloves));

        $this->actingAs($player->user)->get(route('play.market'))->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.design.power', 'Your horseshoes count a third more in your next raid or defence.')
            ->where('items.0.design.for', 'you')
            ->where('workshop.creativity', 1)
            ->where('player.powers.0.label', 'Gathering Gloves')
            ->where('player.powers.0.left', '5 uses left'));
    }
}
