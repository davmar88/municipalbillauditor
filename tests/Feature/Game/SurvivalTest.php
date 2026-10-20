<?php

namespace Tests\Feature\Game;

use App\Game\Clock;
use App\Game\Geography;
use App\Game\Survival;
use App\Game\Weather;
use App\Models\Home;
use App\Models\LootDrop;
use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The turning year and staying alive: animals eat and drink what they
 * carry, go hungry without, die, and leave their things for others.
 */
class SurvivalTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['game.survival.enabled' => true]);
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    public function test_the_season_turns_from_spring_to_winter(): void
    {
        $season = $this->season();
        $season->forceFill(['starts_at' => now(), 'ends_at' => now()->addDays(8)])->save();

        $this->assertSame('spring', Weather::at($season->fresh()));
        $this->travel(2)->days();
        $this->assertSame('summer', Weather::at($season->fresh()));
        $this->assertSame(1.5, Weather::gathering($season->fresh(), 'corn'));
        $this->travel(2)->days();
        $this->assertSame('fall', Weather::at($season->fresh()));
        $this->assertTrue(Weather::dry($season->fresh()));
        $this->travel(2)->days();
        $this->assertSame('winter', Weather::at($season->fresh()));
        $this->assertSame(0.5, Weather::gathering($season->fresh(), 'corn'));

        $player = $this->player();
        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('weather.now', 'winter')
            ->has('weather.times', 4)
            ->where('player.vitals.health', 100));
    }

    public function test_an_animal_eats_and_drinks_what_it_carries_as_the_hours_pass(): void
    {
        $player = $this->player(attributes: ['corn' => 100, 'materials' => ['water' => 20]]);
        $player->forceFill(['food' => 80, 'drink' => 80, 'vitals_at' => now()])->save();

        $this->travel(24)->hours();
        $player = Survival::refresh($player->fresh(), force: true);

        $this->assertLessThan(100, $player->corn, 'It ate.');
        $this->assertLessThan(20, $player->material('water'), 'It drank.');
        $this->assertGreaterThan(30, $player->food);
        $this->assertGreaterThan(30, $player->drink);
        $this->assertSame(100.0, $player->health);
    }

    public function test_with_nothing_to_eat_or_drink_it_dies_and_drops_its_things_on_the_map(): void
    {
        $player = $this->player(attributes: ['corn' => 0, 'materials' => ['wood' => 9, 'water' => 0]]);
        $player->forceFill(['food' => 5, 'drink' => 5, 'health' => 30, 'vitals_at' => now()])->save();
        $this->stand($player, 'granary');

        $this->travel(10)->hours();
        $player = Survival::refresh($player->fresh(), force: true);

        $this->assertNotNull($player->died_at);
        $this->assertSame(1, $player->deaths);
        $this->assertSame(100.0, $player->health);
        $this->assertSame('paddock', $player->fresh()->location()->key);
        $this->assertSame(0, $player->material('wood'));
        $this->assertSame(9, (int) LootDrop::query()->get()->sum(fn (LootDrop $drop) => $drop->materials['wood'] ?? 0));
        $this->assertStringContainsString('Everything you carried lies scattered', Notice::query()->where('player_id', $player->id)->latest('id')->value('text'));

        // Someone finds a pile and picks it up.
        $finder = $this->player();
        $drop = LootDrop::query()->first();
        [$hx, $hy] = Geography::hub('granary');
        $drop->forceFill(['x' => $hx, 'y' => $hy])->save();
        $finder->forceFill(['pos_x' => $drop->x, 'pos_y' => $drop->y, 'region_id' => $finder->valley->region(Geography::regionAt($drop->x, $drop->y))->id])->save();
        $this->actingAs($finder->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page->has('loot', LootDrop::query()->count()));
        $this->actingAs($finder->user)->post(route('play.loot', $drop))->assertRedirect();
        $this->assertNotNull($drop->fresh()->picked_at);
        $this->assertSame($drop->materials['wood'], $finder->fresh()->material('wood'));
    }

    public function test_eat_drink_at_the_trough_and_a_bigger_water_bag(): void
    {
        $player = $this->player(attributes: ['corn' => 300, 'materials' => ['water' => 0]]);
        $player->forceFill(['food' => 50, 'drink' => 20, 'vitals_at' => now()])->save();

        $this->actingAs($player->user)->post(route('play.eat'))->assertRedirect();
        $this->assertSame(100.0, $player->fresh()->food);
        $this->assertSame(250, $player->fresh()->corn);

        // In the Paddock, outside fall, the trough is free.
        $this->actingAs($player->user)->post(route('play.drink'))->assertRedirect();
        $this->assertSame(100.0, $player->fresh()->drink);

        $this->assertSame(20, Survival::waterCap($player->fresh()));
        $this->actingAs($player->user)->post(route('play.water-barrel'))->assertRedirect();
        $this->assertSame(60, Survival::waterCap($player->fresh()));
        $this->assertSame(170, $player->fresh()->corn);
    }

    public function test_faction_members_drink_from_the_store_and_homes_show_their_health(): void
    {
        $player = $this->player(attributes: ['materials' => ['water' => 0]]);
        $faction = $this->faction('Wells', $player);
        $faction->forceFill(['materials' => ['water' => 30]])->save();
        $player->forceFill(['drink' => 20, 'vitals_at' => now()])->save();
        $this->stand($player, 'granary');

        $this->actingAs($player->user)->post(route('play.drink'))->assertRedirect();
        $this->assertGreaterThan(90, $player->fresh()->drink);
        $this->assertLessThan(30, $faction->fresh()->material('water'));

        $home = Home::query()->forceCreate(['player_id' => $player->id, 'region_id' => $player->fresh()->region_id, 'x' => 700, 'y' => 500, 'level' => 1]);
        $home->forceFill(['health' => 40, 'health_at' => now()])->save();
        $this->travel(3)->hours();
        $this->actingAs($player->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('homes', fn ($homes) => collect($homes)->firstWhere('id', $home->id)['health'] === 70));
    }
}
