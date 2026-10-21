<?php

namespace Tests\Feature\Game;

use App\Game\Census;
use App\Game\ValleyAnimals;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
