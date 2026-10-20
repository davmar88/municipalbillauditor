<?php

namespace Tests\Feature\Game;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Animals and faction leaders change their names, once a week. */
class RenameTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    public function test_an_animal_changes_its_name_once_a_week(): void
    {
        $player = $this->player();
        $taken = $this->player();

        $this->actingAs($player->user)->put(route('play.me.name'), ['name' => $taken->name])->assertRedirect();
        $this->assertNotSame($taken->name, $player->fresh()->name, 'Not a name already in the valley.');

        $this->actingAs($player->user)->put(route('play.me.name'), ['name' => 'Moonbeam'])->assertRedirect();
        $this->assertSame('Moonbeam', $player->fresh()->name);

        $this->actingAs($player->user)->get(route('play.me'))->assertInertia(fn (Assert $page) => $page->whereNot('renameFrom', null));
        $this->actingAs($player->user)->put(route('play.me.name'), ['name' => 'Starlight']);
        $this->assertSame('Moonbeam', $player->fresh()->name, 'Not again so soon.');

        $this->travel(8)->days();
        $this->actingAs($player->user)->put(route('play.me.name'), ['name' => 'Starlight']);
        $this->assertSame('Starlight', $player->fresh()->name);
    }

    public function test_only_the_leader_renames_the_faction(): void
    {
        $leader = $this->player();
        $member = $this->player();
        $faction = $this->faction('Old Guard', $leader, $member);
        $member->forceFill(['faction_role' => 'council'])->save();

        $this->actingAs($member->fresh()->user)->put(route('play.faction.rules'), ['name' => 'Usurpers']);
        $this->assertSame('Old Guard', $faction->fresh()->name);

        $this->actingAs($leader->user)->get(route('play.faction'))->assertInertia(fn (Assert $page) => $page->where('faction.canRename', true));
        $this->actingAs($leader->user)->put(route('play.faction.rules'), ['name' => 'New Dawn'])->assertRedirect();
        $this->assertSame('New Dawn', $faction->fresh()->name);
    }
}
