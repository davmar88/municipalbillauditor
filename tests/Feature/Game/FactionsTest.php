<?php

namespace Tests\Feature\Game;

use App\Enums\EntryMode;
use App\Enums\FactionRole;
use App\Enums\Species;
use App\Game\Factions;
use App\Game\GameException;
use App\Models\Faction;
use App\Models\FactionJoinRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Founding and joining factions, and the rules each faction sets.
 */
class FactionsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Factions $factions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->factions = app(Factions::class);
    }

    public function test_founding_costs_corn_and_needs_a_level(): void
    {
        // The test season lets newcomers found; a later season can ask for a level.
        config(['game.levels.found_faction' => 2]);
        $newcomer = $this->player(level: 1, attributes: ['corn' => 500]);
        $founder = $this->player(level: 2, attributes: ['corn' => 500]);

        $this->actingAs($newcomer->user)->post(route('play.faction.store'), ['name' => 'Red Hooves', 'colour' => Faction::COLOURS[0]]);
        $this->assertSame(0, Faction::count());

        $this->actingAs($founder->user)->post(route('play.faction.store'), [
            'name' => 'Red Hooves',
            'colour' => Faction::COLOURS[0],
            'motto' => 'Four hooves, one heart',
            'entry_mode' => 'approval',
            'min_level' => 3,
            'species' => ['dog', 'horse'],
            'entry_fee' => 25,
            'accepts_criminals' => false,
        ])->assertRedirect();

        $faction = Faction::sole();
        $founder->refresh();
        $this->assertSame($faction->id, $founder->faction_id);
        $this->assertSame(FactionRole::Leader, $founder->faction_role);
        $this->assertSame(350, $founder->corn);
        $this->assertSame(EntryMode::Approval, $faction->entry_mode);
        $this->assertSame(3, $faction->min_level);
        $this->assertSame(['dog', 'horse'], $faction->species);
        $this->assertSame(25, $faction->entry_fee);
        $this->assertFalse($faction->accepts_criminals);
    }

    public function test_a_newcomer_can_found_a_faction_on_day_one(): void
    {
        $short = $this->player(attributes: ['corn' => 149]);
        $newcomer = $this->player(attributes: ['corn' => 150]);

        $this->actingAs($short->user)->post(route('play.faction.store'), ['name' => 'Short Straw', 'colour' => Faction::COLOURS[0]]);
        $this->assertSame(0, Faction::count());

        $this->actingAs($newcomer->user)->post(route('play.faction.store'), ['name' => 'Day One', 'colour' => Faction::COLOURS[0]])->assertRedirect();

        $newcomer->refresh();
        $this->assertSame('Day One', $newcomer->faction?->name);
        $this->assertSame(0, $newcomer->corn);
    }

    public function test_an_open_faction_takes_a_recruit_who_pays_the_entry_fee(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Open Gate', $leader);
        $faction->forceFill(['entry_fee' => 30])->save();
        $joiner = $this->player();

        $this->assertSame('joined', $this->factions->join($joiner, $faction));

        $joiner->refresh();
        $this->assertSame(FactionRole::Recruit, $joiner->faction_role);
        $this->assertSame(70, $joiner->corn);
        $this->assertSame(30, $faction->fresh()->treasury);
    }

    public function test_entry_rules_turn_away_the_wrong_animals(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Picky', $leader);
        $faction->forceFill(['min_level' => 4, 'species' => ['dog'], 'accepts_criminals' => false])->save();

        $this->assertRefused(fn () => $this->factions->join($this->player(Species::Dog, level: 2), $faction), 'level 4');
        $this->assertRefused(fn () => $this->factions->join($this->player(Species::Pig, level: 5), $faction), "doesn't take Pigs");
        $this->assertRefused(fn () => $this->factions->join($this->player(Species::Dog, level: 5, attributes: ['wanted' => 1]), $faction), 'criminal record');

        $this->assertSame('joined', $this->factions->join($this->player(Species::Dog, level: 5), $faction));
    }

    public function test_an_approval_faction_turns_joining_into_a_request_the_council_answers(): void
    {
        $leader = $this->player();
        $faction = $this->faction('By Invitation', $leader);
        $faction->forceFill(['entry_mode' => EntryMode::Approval])->save();
        $asker = $this->player();

        $this->assertSame('requested', $this->factions->join($asker, $faction));
        $this->assertNull($asker->fresh()->faction_id);

        $request = FactionJoinRequest::sole();
        $this->actingAs($leader->user)->post(route('play.faction.answer', $request), ['accept' => true])->assertRedirect();

        $this->assertSame($faction->id, $asker->fresh()->faction_id);
        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_recruits_get_their_vote_after_48_hours(): void
    {
        $faction = $this->faction('Patient', $this->player());
        $recruit = $this->player();
        $this->factions->join($recruit, $faction);

        $this->travel(49)->hours();
        $this->factions->promoteRecruits();

        $this->assertSame(FactionRole::Member, $recruit->fresh()->faction_role);
    }

    public function test_when_the_leader_leaves_the_council_takes_over_and_the_last_one_out_closes_the_faction(): void
    {
        $leader = $this->player();
        $council = $this->player();
        $faction = $this->faction('Short Lived', $leader, $council);
        $council->forceFill(['faction_role' => FactionRole::Council])->save();
        $this->hold($faction, 'orchard');

        $this->factions->leave($leader);
        $this->assertSame(FactionRole::Leader, $council->fresh()->faction_role);

        $this->factions->leave($council->fresh());
        $this->assertNull(Faction::find($faction->id));
        $this->assertNull($this->region('orchard')->faction_id, 'Its land goes back to the valley.');
    }

    public function test_the_faction_page_shows_members_to_members_and_the_underworld_to_lone_wolves(): void
    {
        $leader = $this->player();
        $this->faction('Visible', $leader, $this->player());
        $wolf = $this->player(level: 3);

        $this->actingAs($leader->user)->get(route('play.faction'))->assertInertia(fn (Assert $page) => $page
            ->component('game/faction')
            ->has('faction.members', 2)
            ->where('underworld', null));

        $this->actingAs($wolf->user)->get(route('play.faction'))->assertInertia(fn (Assert $page) => $page
            ->where('faction', null)
            ->has('factions', 1)
            ->has('underworld.targets', 2));
    }

    private function assertRefused(callable $attempt, string $because): void
    {
        try {
            $attempt();
            $this->fail("Expected a refusal ({$because}).");
        } catch (GameException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }
}
