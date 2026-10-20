<?php

namespace Tests\Feature\Game;

use App\Game\Valleys;
use App\Models\Notice;
use App\Models\Player;
use App\Models\User;
use App\Models\Valley;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * New valleys: newcomers start in the oldest valley with room, and the next
 * one opens by itself when every valley is full or closed.
 */
class ValleysTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        config(['game.valleys.full_at' => 2]);
    }

    private function newcomer(): Player
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('play.start.store'), ['name' => 'Newbie '.chr(65 + User::query()->count()), 'species' => 'pig', 'adult' => '1'])->assertRedirect(route('play'));

        return $user->fresh()->player;
    }

    public function test_newcomers_fill_a_valley_then_the_next_one_opens(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->player()->forceFill(['user_id' => $admin->id])->save();

        // One animal is in already: one more fills Hollowmere.
        $this->assertSame($this->valley->id, $this->newcomer()->valley_id);
        $this->actingAs(User::factory()->create())->get(route('play.start'))->assertInertia(fn (Assert $page) => $page->where('valley', 'Briarwood'));

        $third = $this->newcomer();
        $briarwood = Valley::query()->where('name', 'Briarwood')->sole();
        $this->assertSame($briarwood->id, $third->valley_id);
        $this->assertNotNull($briarwood->currentSeason(), 'It opens with its own season.');
        $this->assertSame($this->valley->regions()->count(), $briarwood->regions()->count(), 'And its own map.');
        $this->assertStringContainsString('Briarwood opened for newcomers', Notice::query()->where('player_id', $admin->player->id)->value('text'));

        // The next newcomer fills Briarwood, not a third valley.
        $this->assertSame($briarwood->id, $this->newcomer()->valley_id);
        $this->assertSame(2, Valley::query()->count());
    }

    public function test_a_closed_valley_takes_no_newcomers_and_the_owner_sees_every_valley(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.valleys.update', $this->valley), ['closed' => true])->assertRedirect();
        $this->assertNotSame($this->valley->id, $this->newcomer()->valley_id);

        $this->actingAs($admin)->post(route('admin.valleys.store'))->assertRedirect();
        $this->actingAs($admin)->get(route('admin.valleys'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/valleys')
            ->has('valleys', 3)
            ->where('valleys.0.closed', true)
            ->where('valleys.1.takingNewcomers', true)
            ->where('fullAt', 2));

        $this->actingAs($this->player()->user)->get(route('admin.valleys'))->assertForbidden();
        $this->assertSame(['Hollowmere', 'Briarwood', 'Thornvale'], Valley::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame('Marrowmead', Valleys::nextName());
    }
}
