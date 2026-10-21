<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Enums\Study;
use App\Game\GameException;
use App\Game\Market;
use App\Game\Powers;
use App\Game\Research;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Notice;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnologyAndSpyingTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    private function item(Player $owner, ItemTemplate $template, int $serial = 1): Item
    {
        $design = ItemDesign::create(['player_id' => $owner->id, 'template' => $template, 'name' => 'Test '.$template->label(), 'colours' => ['#888888', '#552200'], 'pattern' => 'plain', 'status' => 'approved']);

        return Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => $serial]);
    }

    public function test_technology_unlocks_the_spyglass_and_it_never_goes_on_sale(): void
    {
        $maker = $this->player(Species::Pig, 10, ['creativity_skill' => 100000]);
        $ours = $this->faction('Vlinderstadt', $maker);
        $market = app(Market::class);

        // Without Technology, no Spyglass.
        try {
            $market->design($maker, ItemTemplate::Spyglass, 'Far Eye', ['#b39aff', '#41d1ff'], 'plain', 10);
            $this->fail('A Spyglass needs Technology.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Technology to level 1', $e->getMessage());
        }

        // Technology stops at 3, one weapon a level.
        $this->assertSame(3, Study::Technology->maxLevel());
        $this->assertStringContainsString('Spyglass, Crossbow, Battering Ram', Study::Technology->does(3));

        $ours->forceFill(['studies' => ['technology' => 1]])->save();
        $this->assertSame(1, Research::level($ours->fresh(), Study::Technology));
        $design = $market->design($maker->fresh(), ItemTemplate::Spyglass, 'Far Eye', ['#b39aff', '#41d1ff'], 'plain', 10);
        $this->assertSame(ItemTemplate::Spyglass, $design->template);

        // The Crossbow waits for level 2.
        $this->expectException(GameException::class);

        try {
            $glass = $this->item($maker, ItemTemplate::Spyglass);
            $market->list($maker->fresh(), $glass, 20, 'apples');
            $this->fail('A Spyglass is not for sale.');
        } catch (GameException $e) {
            $this->assertStringContainsString('for spying, not for selling', $e->getMessage());
        }

        $market->design($maker->fresh(), ItemTemplate::Crossbow, 'Bolt', ['#b39aff', '#41d1ff'], 'plain', 10);
    }

    public function test_a_spyglass_shows_a_rival_faction_to_the_spys_faction_only(): void
    {
        $spy = $this->player(Species::Goat);
        $friend = $this->player(Species::Dog);
        $this->faction('Vlinderstadt', $spy, $friend);

        $rivalLeader = $this->player(Species::Pig);
        $rival = $this->faction('Thistle Hollow', $rivalLeader);
        $rival->forceFill(['treasury' => 640, 'materials' => ['wood' => 30, 'iron' => 4]])->save();
        $this->item($rivalLeader, ItemTemplate::Sword);
        $this->item($rivalLeader, ItemTemplate::Sword, 2);

        // No Spyglass, no spying.
        $this->actingAs($spy->user)->post(route('play.factions.spy', $rival));
        $this->actingAs($spy->user)->get(route('play.factions.show', $rival))
            ->assertInertia(fn ($page) => $page->where('spy.spyglasses', 0)->where('spy.report', null));

        $glass = $this->item($spy, ItemTemplate::Spyglass);
        $this->actingAs($spy->user)->post(route('play.factions.spy', $rival))->assertRedirect();
        $this->assertNotNull($glass->fresh()->used_at);

        // The whole faction reads it: the store, the strength and the weapons.
        $this->actingAs($friend->user)->get(route('play.factions.show', $rival))
            ->assertInertia(fn ($page) => $page
                ->where('spy.report.store.corn', 640)
                ->where('spy.report.store.materials.wood', 30)
                ->where('spy.report.strength.members', 1)
                ->where('spy.report.weapons.0.label', 'Iron Sword')
                ->where('spy.report.weapons.0.count', 2)
                ->where('spy.report.by', $spy->name));

        // The faction spied on never knows, and you can't spy on your own.
        $this->actingAs($rivalLeader->user)->get(route('play.factions.show', $rival))
            ->assertInertia(fn ($page) => $page->where('spy', null));
        $this->assertSame(0, Notice::query()->where('player_id', $rivalLeader->id)->where('text', 'like', '%spied%')->count());
        $this->assertSame(1, Notice::query()->where('player_id', $friend->id)->where('text', 'like', '%spied%')->count());
    }

    public function test_the_crossbow_and_the_ram_are_weapons(): void
    {
        $archer = $this->player(Species::Dog);
        $this->faction('Vlinderstadt', $archer);

        app(Powers::class)->use($archer, $this->item($archer, ItemTemplate::Crossbow));
        $this->assertSame(Powers::CROSSBOW, Powers::weapon($archer, 'defence'));
        $this->assertSame(Powers::CROSSBOW, Powers::weapon($archer, 'attack'));
        $this->assertSame(1.0, Powers::weapon($archer, 'attack'));

        app(Powers::class)->use($archer, $this->item($archer, ItemTemplate::Ram, 2));
        $this->assertTrue(Powers::ram($archer));
        $this->assertFalse(Powers::ram($archer));

        // A Spyglass is used on a faction's page, not from the bag.
        $this->expectExceptionMessage('use the Spyglass there');
        app(Powers::class)->use($archer, $this->item($archer, ItemTemplate::Spyglass, 3));
    }
}
