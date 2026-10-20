<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Game\Apples;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Dice;
use App\Game\GameException;
use App\Game\Geography;
use App\Game\Horseshoes;
use App\Game\Messages;
use App\Game\Movement;
use App\Models\Crime;
use App\Models\Notice;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Robbing other animals, buying horseshoes with Apples, and who's online:
 * in the game and on the landing page.
 */
class RobberyAndOnlineTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    /** Two animals side by side in the Granary. */
    private function pair(): array
    {
        $robber = $this->player(level: 3, attributes: ['corn' => 0]);
        $victim = $this->player(attributes: ['corn' => 400, 'materials' => ['wood' => 40]]);
        $this->stand($robber, 'granary');
        $this->stand($victim, 'granary');

        return [$robber->fresh(), $victim->fresh()];
    }

    private function escape(Crime $crime): void
    {
        $chance = app(Crimes::class)->catchChance($this->season(), $crime);
        $seed = 1;
        while (Dice::roll($seed, 'caught') < $chance) {
            $seed++;
        }
        $crime->forceFill(['seed' => $seed])->save();
    }

    public function test_an_animal_robs_another_standing_close_even_a_faction_mate(): void
    {
        [$robber, $victim] = $this->pair();
        $this->faction('Mates', $victim, $robber);

        $this->actingAs($robber->user)->post(route('play.crime'), ['kind' => 'rob', 'player_id' => $victim->id])->assertRedirect();
        $crime = Crime::sole();
        $this->assertSame(CrimeKind::Rob, $crime->kind);
        $this->escape($crime);

        $this->travel(Crimes::ROB_MINUTES + 1)->minutes();
        app(Crimes::class)->resolveDue($this->season());

        $this->assertSame(60, $robber->fresh()->corn, 'A share of what the victim carried.');
        $this->assertSame(340, $victim->fresh()->corn);
        $this->assertSame(6, $robber->fresh()->material('wood'));
        $this->assertStringContainsString('Someone robbed you of 60 Corn, 6 wood', Notice::query()->where('player_id', $victim->id)->latest('id')->value('text'));

        // Not again so soon, by anyone, and not from afar.
        $this->expectExceptionMessage('robbed someone not long ago');
        app(Crimes::class)->commit($robber->fresh(), CrimeKind::Rob, target: $this->player());
    }

    public function test_a_robber_must_stand_close_and_leave_newcomers_alone(): void
    {
        [$robber, $victim] = $this->pair();
        $this->stand($victim, 'quarry');

        try {
            app(Crimes::class)->commit($robber, CrimeKind::Rob, target: $victim->fresh());
            $this->fail('Too far.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Get closer', $e->getMessage());
        }

        // Anywhere in the same region is close enough, however far apart they stopped.
        $this->stand($victim, 'granary');
        $robber->fresh()->forceFill(['pos_x' => null, 'pos_y' => null])->save();
        $victim->fresh()->forceFill(['pos_x' => null, 'pos_y' => null])->save();
        [$hx, $hy] = Movement::position($victim->fresh());
        $victim->fresh()->forceFill(['pos_x' => $hx + 80, 'pos_y' => $hy])->save();
        $this->assertSame('granary', Geography::regionAt($hx + 80, $hy));
        $this->assertSame(CrimeKind::Rob, app(Crimes::class)->commit($robber->fresh(), CrimeKind::Rob, target: $victim->fresh())->kind);
        Crime::query()->delete();

        // Newcomers can be robbed from their first day.
        $newcomer = $this->player(attributes: ['shield_until' => now()->addDay(), 'corn' => 100]);
        $this->stand($newcomer, 'granary');
        $this->assertSame(CrimeKind::Rob, app(Crimes::class)->commit($robber, CrimeKind::Rob, target: $newcomer->fresh())->kind);
    }

    public function test_horseshoes_bought_with_apples_stack_past_the_usual_ten(): void
    {
        $player = $this->player(attributes: ['horseshoes' => 10]);
        Apples::award($player, 20, 'Test', 'test:shoes');

        $this->actingAs($player->user)->post(route('play.apples.horseshoes'), ['count' => 10])->assertRedirect();

        $this->assertSame(20, Horseshoes::current($player->fresh()));
        $this->assertSame(15, $player->fresh()->apples);
        $this->assertSame(['refused'], (function () use ($player) {
            try {
                Horseshoes::buy($player->fresh(), 7);
            } catch (GameException) {
                return ['refused'];
            }

            return ['sold'];
        })(), 'Only the packs on offer.');
    }

    public function test_the_game_and_the_landing_page_show_who_is_online(): void
    {
        $here = $this->player();
        $away = $this->player();
        $away->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->faction('Visible', $here);

        $this->actingAs($here->user)->get(route('play.online'))->assertInertia(fn (Assert $page) => $page
            ->component('game/online')
            ->has('animals', 1)
            ->where('animals.0.name', $here->name)
            ->where('total', 2));
        $this->assertNotNull(Player::query()->find($here->id)->last_seen_at);

        // The corner slide-out lists the others online, ready to chat, and your chats.
        $other = $this->player();
        $other->forceFill(['last_seen_at' => now()])->save();
        app(Messages::class)->send($other, $here, 'Hello there');
        $this->actingAs($here->user)->getJson(route('play.dock'))
            ->assertOk()
            ->assertJsonPath('online.0.id', $other->id)
            ->assertJsonCount(1, 'online')
            ->assertJsonPath('conversations.0.animal.id', $other->id)
            ->assertJsonPath('conversations.0.unread', 1);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('The valleys right now')
            ->assertSee('Factions of Hollowmere')
            ->assertSee('Visible');
    }
}
