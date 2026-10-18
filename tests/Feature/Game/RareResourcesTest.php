<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Farms;
use App\Game\GameException;
use App\Game\Gathering;
use App\Game\Powers;
use App\Game\Skills;
use App\Game\ValleyBuilder;
use App\Models\Attack;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What only the farms beyond the fences grow, who may gather it, and the
 * recipes that need it.
 */
class RareResourcesTest extends TestCase
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
            'status' => 'approved',
        ]);

        return Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => 1]);
    }

    public function test_each_farm_grows_something_hollowmere_does_not(): void
    {
        $this->assertSame(['honey', 'herbs', 'iron'], [
            $this->region('thornbury')->produces,
            $this->region('brookside')->produces,
            $this->region('willowby')->produces,
        ]);

        // A valley laid out before the farms had crops gets them on the next setup.
        $this->region('willowby')->forceFill(['produces' => 'corn'])->save();
        app(ValleyBuilder::class)->build();
        $this->assertSame('iron', $this->region('willowby')->produces);
    }

    public function test_anyone_gathers_on_an_unclaimed_farm_but_only_holders_on_a_claimed_one(): void
    {
        $wanderer = $this->player();
        $this->stand($wanderer, 'thornbury');

        app(Gathering::class)->gather($wanderer, $this->region('thornbury'));
        $this->assertSame(Gathering::RARE, $wanderer->fresh()->material('honey'));

        $keeper = $this->player();
        $faction = $this->faction('Beekeepers', $keeper);
        $this->hold($faction, 'thornbury');
        $this->stand($keeper, 'thornbury');

        app(Gathering::class)->gather($keeper, $this->region('thornbury'));
        $this->assertSame(4, $keeper->fresh()->material('honey'), 'Half as much again on your own land.');

        $this->expectExceptionMessage("Thornbury Farm's honey belongs to Beekeepers");
        app(Gathering::class)->gather($wanderer->fresh(), $this->region('thornbury'));
    }

    public function test_the_new_recipes_need_the_farms_crops(): void
    {
        $this->assertSame(['iron' => 4, 'stone' => 3, 'wood' => 2], ItemTemplate::Gate->materials());
        $this->assertArrayHasKey('honey', ItemTemplate::Cake->materials());
        $this->assertArrayHasKey('herbs', ItemTemplate::Kit->materials());
    }

    public function test_an_iron_pickaxe_doubles_materials_but_not_corn(): void
    {
        $player = $this->player();
        $this->powers->use($player, $this->item($player, ItemTemplate::Pickaxe));

        $this->stand($player, 'hayfield');
        app(Gathering::class)->gather($player->fresh(), $this->region('hayfield'));
        $this->assertSame(120, $player->fresh()->corn, 'Corn as usual, and no use spent.');

        $this->stand($player, 'quarry');
        app(Gathering::class)->gather($player->fresh(), $this->region('quarry'));
        $this->assertSame(10, $player->fresh()->material('stone'));
        $this->assertSame([['kind' => 'pickaxe', 'label' => 'Iron Pickaxe', 'left' => '9 uses left']], Powers::summary($player->fresh()));
    }

    public function test_a_honey_cake_doubles_skills_for_six_hours(): void
    {
        $player = $this->player();
        $this->powers->use($player, $this->item($player, ItemTemplate::Cake));

        Skills::award($player->id, Skills::MARKET, 5, 'Test', 'cake:1');
        $this->assertSame(10, (int) $player->fresh()->market_skill);

        $this->travel(7)->hours();
        Skills::award($player->id, Skills::MARKET, 5, 'Test', 'cake:2');
        $this->assertSame(15, (int) $player->fresh()->market_skill);
    }

    public function test_an_iron_lock_keeps_a_home_from_burning(): void
    {
        $wolf = $this->player(level: 3);
        $target = $this->player();
        $this->faction('Homesteaders', $target);
        $this->powers->use($target->fresh(), $this->item($target, ItemTemplate::Lock));

        $this->expectExceptionMessage('Iron Lock');
        app(Crimes::class)->commit($wolf, CrimeKind::Burn, null, $target->fresh());
    }

    public function test_a_healers_kit_frees_the_whole_faction_from_the_pound(): void
    {
        $healer = $this->player();
        $caught = $this->player(attributes: ['pound_until' => now()->addHours(5)]);
        $alsoCaught = $this->player(attributes: ['pound_until' => now()->addHours(2)]);
        $this->faction('Medics', $healer, $caught, $alsoCaught);

        $told = $this->powers->use($healer->fresh(), $this->item($healer, ItemTemplate::Kit));

        $this->assertStringContainsString('2 of your faction walked out', $told);
        $this->assertFalse($caught->fresh()->inPound());
        $this->assertFalse($alsoCaught->fresh()->inPound());
    }

    public function test_an_iron_gate_keeps_raiders_out_for_six_hours_but_not_off_the_farmhouse(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $this->faction('Red Hooves', ...$red);
        $keeper = $this->player();
        $faction = $this->faction('Gatekeepers', $keeper);
        $this->hold($faction, 'henhouse', 'farmhouse');

        $this->stand($keeper, 'farmhouse');

        try {
            $this->powers->use($keeper->fresh(), $this->item($keeper, ItemTemplate::Gate));
            $this->fail('The Farmhouse is never gated.');
        } catch (GameException $e) {
            $this->assertStringContainsString("can't be gated", $e->getMessage());
        }

        $this->stand($keeper, 'henhouse');
        $this->powers->use($keeper->fresh(), $this->item($keeper, ItemTemplate::Gate));

        $this->actingAs($keeper->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('regions.11.key', 'henhouse')
            ->whereNot('regions.11.gatedUntil', null));

        try {
            app(Battles::class)->launch($red[0], $this->region('henhouse'), 1);
            $this->fail('The gate is shut.');
        } catch (GameException $e) {
            $this->assertStringContainsString('shut behind an Iron Gate', $e->getMessage());
        }

        $this->travel(7)->hours();
        app(Battles::class)->launch($red[0]->fresh(), $this->region('henhouse'), 1);
        $this->assertSame(1, Attack::count(), 'Once the gate comes down, raids march again.');
    }

    public function test_an_iron_plough_doubles_what_the_farms_pay(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Ploughmen', $leader);
        $this->hold($faction, 'willowby');
        $this->powers->use($leader->fresh(), $this->item($leader, ItemTemplate::Plough));

        app(Farms::class)->payIncome($this->season());

        $this->assertSame(2 * Farms::INCOME, $faction->fresh()->treasury);
    }
}
