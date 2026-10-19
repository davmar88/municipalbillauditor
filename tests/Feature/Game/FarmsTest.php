<?php

namespace Tests\Feature\Game;

use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Farms;
use App\Game\GameException;
use App\Game\Gathering;
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

    public function test_the_valley_has_twelve_farms_beyond_the_fences_in_three_rings(): void
    {
        $this->assertSame(28, $this->valley->regions()->where('wild', false)->whereNotIn('ring', ['city', 'frontier'])->count());
        $this->assertSame(36, $this->valley->regions()->where('wild', true)->count(), 'Three patches of wild land round each farm.');
        $this->assertSame(['fennick'], $this->region('hartley')->neighbours()->where('wild', false)->where('ring', 'farm')->pluck('key')->all(), 'The third ring is reached from the second.');
        $this->assertSame(['gold', 'copper', 'pears', 'carrots', 'berries', 'pumpkins'], collect(['coldharbour', 'copperhill', 'northfold', 'hartley', 'rushmere', 'southmere'])->map(fn (string $key) => $this->region($key)->produces)->all());
        $this->assertEqualsCanonicalizing(['thornbury', 'northfold', 'coldharbour'], $this->region('ashcombe')->neighbours()->where('wild', false)->pluck('key')->all(), 'The second ring is reached from the first, and leads on to the third.');
        $this->assertSame(['iron', 'honey', 'herbs'], [$this->region('ashcombe')->produces, $this->region('fennick')->produces, $this->region('marlow')->produces]);
        $this->assertEqualsCanonicalizing(['orchard', 'big_barn', 'hayfield', 'ashcombe'], $this->region('thornbury')->neighbours()->where('wild', false)->pluck('key')->all());
        $this->assertEqualsCanonicalizing(['paddock', 'marlow', 'copperhill'], $this->region('willowby')->neighbours()->where('wild', false)->pluck('key')->all());
        $this->assertTrue($this->region('big_barn')->neighbours()->where('key', 'thornbury')->exists());
    }

    public function test_the_frontier_fills_the_valley_with_open_land(): void
    {
        $frontier = $this->valley->regions()->where('ring', 'frontier')->get();

        $this->assertCount(83, $frontier);
        $this->assertSame('gold', $this->region('glitter_gully')->produces);
        $this->assertSame('wood', $this->region('blackthorn_wood')->produces);
        // Every bit of it can be reached from somewhere.
        $frontier->each(fn ($region) => $this->assertTrue($region->neighbours()->exists(), "{$region->name} borders nothing."));

        // Anyone can walk out and gather there.
        $player = $this->player();
        $this->stand($player, 'blackthorn_wood');
        $this->assertSame('wood', app(Gathering::class)->gather($player->fresh(), $this->region('blackthorn_wood'))['what']);
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

    public function test_a_farm_needs_water_to_be_gathered_and_to_pay(): void
    {
        $farm = $this->region('thornbury');
        $this->assertFalse($farm->isParched(), 'A farm starts with a day of water.');

        $farm->forceFill(['watered_until' => now()->subMinute()])->save();
        $player = $this->player(attributes: ['materials' => ['water' => 25]]);
        $this->stand($player, 'thornbury');

        try {
            app(Gathering::class)->gather($player->fresh(), $farm->fresh());
            $this->fail('Gathered a parched farm.');
        } catch (GameException $e) {
            $this->assertStringContainsString('parched', $e->getMessage());
        }

        $watered = app(Farms::class)->water($player->fresh(), $farm->fresh());
        $this->assertEqualsWithDelta(now()->addHours(Farms::WATER_HOURS)->timestamp, $watered->watered_until->timestamp, 5);
        $this->assertSame(15, $player->fresh()->materials['water']);
        $this->assertSame('honey', app(Gathering::class)->gather($player->fresh(), $farm->fresh())['what']);

        // Water fetched from a mere or a spring.
        $this->assertSame('water', $this->region('heron_mere')->produces);
    }

    public function test_a_faction_digs_a_trench_from_the_water_and_a_rival_fills_it_in(): void
    {
        // A farm with water near enough to dig from.
        $farm = $this->valley->regions()->where('ring', 'farm')->where('wild', false)->get()
            ->first(fn ($region) => Farms::fieldKeys($region->key) !== [] && Farms::trenchPath($region) !== null);
        $this->assertNotNull($farm, 'Some farm has water within reach.');
        $path = Farms::trenchPath($farm);
        $this->assertSame('water', $this->region($path[0])->produces);
        $this->assertSame($farm->key, end($path));
        $this->assertLessThanOrEqual(Farms::TRENCH_REACH + 1, count($path));

        $leader = $this->player();
        $member = $this->player();
        $faction = $this->faction('Diggers', $leader, $member);
        $faction->forceFill(['treasury' => 1000, 'materials' => ['wood' => 100, 'stone' => 100]])->save();
        $this->hold($faction, $farm->key);
        $this->stand($leader, $farm->key);
        $this->stand($member, $farm->key);

        try {
            app(Farms::class)->dig($member->fresh(), $farm->fresh());
            $this->fail('A member dug a trench.');
        } catch (GameException $e) {
            $this->assertStringContainsString('leader and council', $e->getMessage());
        }

        app(Farms::class)->dig($leader->fresh(), $farm->fresh());
        $stretches = count($path) - 1;
        $this->assertSame($path, $farm->fresh()->trench);
        $this->assertSame(1000 - Farms::TRENCH_CORN * $stretches, $faction->fresh()->treasury);
        $this->assertSame(100 - Farms::TRENCH_WOOD * $stretches, $faction->fresh()->materials['wood']);

        // It never runs dry, and the patches it grows into are fed too.
        $farm->forceFill(['watered_until' => now()->subDay()])->save();
        $this->assertFalse($farm->fresh()->isParched());
        $field = $this->region(Farms::fieldKeys($farm->key)[0]);
        $field->forceFill(['wild' => false, 'watered_until' => null])->save();
        $this->assertFalse($field->fresh()->isParched());

        // A rival standing where it runs fills it in.
        $rival = $this->player();
        $this->stand($rival, $path[0]);
        app(Farms::class)->fill($rival->fresh(), $farm->fresh());
        $this->assertNull($farm->fresh()->trench);
        $this->assertTrue($farm->fresh()->isParched());
        $this->assertTrue($field->fresh()->isParched());
    }
}
