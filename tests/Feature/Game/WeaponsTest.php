<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Dice;
use App\Game\Plunderers;
use App\Game\Powers;
use App\Models\BattleCommitment;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Weapons, made and sold like any item and used up in fights: a Pitchfork
 * for raids, a Sling against plunderers, an Iron Sword for both, a Cudgel
 * for robbery.
 */
class WeaponsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Powers $powers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
        $this->powers = app(Powers::class);
    }

    private function weapon(Player $owner, ItemTemplate $template): Item
    {
        $design = ItemDesign::create(['player_id' => $owner->id, 'template' => $template, 'name' => $template->label(), 'colours' => ['#8a8f99', '#c0392b'], 'pattern' => 'plain', 'status' => 'approved']);

        return Item::create(['item_design_id' => $design->id, 'player_id' => $owner->id, 'serial' => 1]);
    }

    public function test_weapons_are_for_their_owner_and_sell_like_any_item(): void
    {
        foreach ([ItemTemplate::Pitchfork, ItemTemplate::Cudgel, ItemTemplate::Sling, ItemTemplate::Sword] as $template) {
            $this->assertSame('you', $template->for());
            $this->assertNotNull($template->power());
        }

        $this->assertSame(2, ItemTemplate::Sword->creativity());
    }

    public function test_a_pitchfork_makes_raids_hit_harder_three_times(): void
    {
        $raider = $this->player(attributes: ['horseshoes' => 10]);
        $attackers = $this->faction('Forks', $raider, $this->player(), $this->player());
        $defenders = $this->faction('Targets', $this->player());
        $this->hold($attackers, 'granary');
        $this->hold($defenders, 'henhouse');
        $this->powers->use($raider->fresh(), $this->weapon($raider, ItemTemplate::Pitchfork));

        app(Battles::class)->launch($raider->fresh(), $this->region('henhouse'), 1);

        $this->assertEqualsWithDelta(Powers::PITCHFORK, BattleCommitment::sole()->boost, 0.01);
        $this->assertSame(Powers::PITCHFORK, Powers::weapon($raider->fresh(), 'attack'), 'Two charges left.');
        $this->assertSame(1.0, Powers::weapon($raider->fresh(), 'defence'), 'Not for defending.');
    }

    public function test_a_sling_doubles_a_fighter_against_plunderers(): void
    {
        $this->season()->forceFill(['starts_at' => now()->subDay()])->save();
        $holders = $this->faction('Holders', $this->player());
        $this->hold($holders, 'thornbury');
        $band = app(Plunderers::class)->muster($this->season());
        $fighter = $this->player(attributes: ['horseshoes' => 10]);
        $this->faction('Slingers', $fighter);
        $this->powers->use($fighter->fresh(), $this->weapon($fighter, ItemTemplate::Sling));

        $joined = app(Plunderers::class)->fight($fighter->fresh(), $band, 2);

        $this->assertSame(Powers::SLING, $joined->boost);
        $this->assertEqualsWithDelta(2 * Powers::SLING * $fighter->species->attack(), Plunderers::strength($band), 0.01);
    }

    public function test_a_cudgel_takes_twice_as_much_and_gets_caught_less(): void
    {
        $robber = $this->player(level: 3, attributes: ['corn' => 0]);
        $victim = $this->player(attributes: ['corn' => 400]);
        $this->stand($robber, 'granary');
        $this->stand($victim, 'granary');
        $this->powers->use($robber->fresh(), $this->weapon($robber, ItemTemplate::Cudgel));

        $crime = app(Crimes::class)->commit($robber->fresh(), CrimeKind::Rob, target: $victim->fresh());
        $this->assertTrue($crime->armed);
        $unarmed = $crime->replicate()->forceFill(['armed' => false]);
        $this->assertEqualsWithDelta(app(Crimes::class)->catchChance($this->season(), $unarmed) * Powers::CUDGEL_CATCH, app(Crimes::class)->catchChance($this->season(), $crime), 0.001);

        $seed = 1;
        while (Dice::roll($seed, 'caught') < app(Crimes::class)->catchChance($this->season(), $crime)) {
            $seed++;
        }
        $crime->forceFill(['seed' => $seed])->save();
        $this->travel(Crimes::ROB_MINUTES + 1)->minutes();
        app(Crimes::class)->resolveDue($this->season());

        $this->assertSame(120, $robber->fresh()->corn, 'Twice the usual 15%.');
    }
}
