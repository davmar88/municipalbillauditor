<?php

namespace Tests\Feature\Game;

use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Farms;
use App\Game\GameException;
use App\Game\Tick;
use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The farms beyond Hollowmere's fences: bought outright, taken in raids,
 * and paying their holders every half hour.
 */
class FarmsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    public function test_the_valley_has_three_farms_beyond_the_fences(): void
    {
        $this->assertSame(19, $this->valley->regions()->where('wild', false)->count());
        $this->assertSame(9, $this->valley->regions()->where('wild', true)->count(), 'Three patches of wild land round each farm.');
        $this->assertEqualsCanonicalizing(['orchard', 'big_barn', 'hayfield'], $this->region('thornbury')->neighbours()->where('wild', false)->pluck('key')->all());
        $this->assertEqualsCanonicalizing(['paddock'], $this->region('willowby')->neighbours()->where('wild', false)->pluck('key')->all());
        $this->assertTrue($this->region('big_barn')->neighbours()->where('key', 'thornbury')->exists());
    }

    public function test_a_leader_buys_an_unclaimed_farm_from_the_treasury(): void
    {
        $leader = $this->player();
        $member = $this->player();
        $faction = $this->faction('Farmers', $leader, $member);
        $faction->forceFill(['treasury' => 350])->save();
        $neighbour = $this->player();

        $this->actingAs($member->user)->post(route('play.buy-farm', $this->region('thornbury')))->assertRedirect();
        $this->assertNull($this->region('thornbury')->faction_id, 'Only the leader and council buy.');

        $this->actingAs($leader->user)->post(route('play.buy-farm', $this->region('thornbury')))->assertRedirect();

        $this->assertSame($faction->id, $this->region('thornbury')->faction_id);
        $this->assertSame(50, $faction->fresh()->treasury);
        $this->assertSame('🌾 Farmers bought Thornbury Farm.', Notice::query()->where('player_id', $neighbour->id)->value('text'));
    }

    public function test_only_unclaimed_farms_are_for_sale(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Rich', $leader);
        $faction->forceFill(['treasury' => 1000])->save();
        $rival = $this->faction('Owners', $this->player());
        $this->hold($rival, 'brookside');

        try {
            app(Farms::class)->buy($leader, $this->region('granary'));
            $this->fail('Hollowmere regions are not for sale.');
        } catch (GameException $e) {
            $this->assertStringContainsString("isn't for sale", $e->getMessage());
        }

        $this->expectExceptionMessage('Take it in a raid instead');
        app(Farms::class)->buy($leader, $this->region('brookside'));
    }

    public function test_a_farm_held_by_a_rival_falls_to_a_raid_from_next_door(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $redFaction = $this->faction('Red Hooves', ...$red);
        $this->hold($redFaction, 'big_barn');
        $this->stand($red[0], 'big_barn');
        $owners = $this->faction('Owners', $this->player());
        $this->hold($owners, 'thornbury');

        app(Battles::class)->launch($red[0]->fresh(), $this->region('thornbury'), 2);
        $this->travel(61)->minutes();
        app(Battles::class)->resolveDue($this->season());

        $this->assertSame($redFaction->id, $this->region('thornbury')->faction_id);
    }

    public function test_farms_pay_their_holders_every_half_hour(): void
    {
        $faction = $this->faction('Farmers', $this->player());
        $this->hold($faction, 'willowby', 'brookside');

        app(Tick::class)->run($this->valley->fresh());

        $this->assertSame(2 * Farms::INCOME, $faction->fresh()->treasury);
    }
}
