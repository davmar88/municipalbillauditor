<?php

namespace Tests\Feature\Game;

use App\Game\Census;
use App\Game\ValleyAnimals;
use App\Models\Player;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ValleyAnimalsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    public function test_valley_animals_come_in_marked_and_go_about_their_day(): void
    {
        $person = $this->player();
        $animals = app(ValleyAnimals::class);

        $this->assertSame(5, $animals->bring($this->valley, 5));
        $npcs = Player::query()->where('npc', true)->get();
        $this->assertCount(5, $npcs);
        $this->assertTrue($npcs->every(fn (Player $animal) => ! $animal->news_emails && str_ends_with($animal->user->email, '@npc.animalfarm.invalid')));

        // Bringing more skips the names already taken.
        $this->assertSame(2, $animals->bring($this->valley, 2));
        $this->assertSame(7, Player::query()->where('npc', true)->count());

        // A few minutes of valley life: they walk, gather, camp or chat, and stay online.
        for ($i = 0; $i < 5; $i++) {
            $animals->live($this->valley->id);
            $this->travel(3)->minutes();
        }
        $this->assertTrue(Player::query()->where('npc', true)->where(fn ($q) => $q->whereNotNull('walk_arrives_at')->orWhere('corn', '>', 60))->exists());

        // The landing page counts only people.
        $this->assertSame(1, collect(Census::valleys())->firstWhere('name', $this->valley->name)['animals']);

        // Shown as valley animals in the Players list.
        $this->actingAs($person->user)->getJson(route('play.dock'))->assertOk()->assertJsonPath('online.0.npc', true);
    }

    public function test_valley_animals_join_a_faction_found_their_own_and_go_away_when_switched_off(): void
    {
        $leader = $this->player();
        $ours = $this->faction('Vlinderstadt', $leader);
        $ours->forceFill(['treasury' => 0, 'materials' => []])->save();
        $animals = app(ValleyAnimals::class);

        // Some join our faction, and hand what they gather to its store.
        $this->assertSame(3, $animals->joinFaction($ours, 3));
        $helper = Player::query()->where('npc', true)->where('faction_id', $ours->id)->first();
        $helper->forceFill(['corn' => 100, 'materials' => ['wood' => 20]])->save();
        (new ReflectionMethod(ValleyAnimals::class, 'giveToStore'))->invoke($animals, $helper);
        $this->assertSame(80, $ours->fresh()->treasury);
        $this->assertSame(20, $ours->fresh()->material('wood'));
        $this->assertSame(20, $helper->fresh()->corn);

        // Others found factions of their own, with Corn and a farm: something to rob.
        $made = $animals->foundFactions($this->valley, 2, 4);
        $this->assertCount(2, $made);
        $this->assertSame(4, $made[0]->members()->count());
        $this->assertSame(300, $made[0]->treasury);
        $this->assertTrue(Region::query()->where('faction_id', $made[0]->id)->exists());

        // Switched off: they leave, their own factions break up, and they drop off the map.
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->put(route('admin.valley-animals'), ['on' => false])->assertRedirect();
        $this->assertFalse(ValleyAnimals::on());
        $this->assertSame(0, Player::query()->where('npc', true)->whereNotNull('faction_id')->count());
        $this->assertNull($made[0]->fresh());
        $this->assertSame(1, $ours->fresh()->members()->count());
        $this->actingAs($leader->user)->getJson(route('play.dock'))->assertJsonMissing(['npc' => true]);

        $this->actingAs($admin)->put(route('admin.valley-animals'), ['on' => true]);
        $this->assertTrue(ValleyAnimals::on());
    }
}
