<?php

namespace Tests\Feature\Game;

use App\Enums\BuildingKind;
use App\Enums\Species;
use App\Game\Clock;
use App\Game\Dice;
use App\Game\GameException;
use App\Game\LawTemplate;
use App\Game\Plunderers;
use App\Game\Wall;
use App\Models\Building;
use App\Models\Faction;
use App\Models\Notice;
use App\Models\Player;
use App\Models\PlunderBand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Irongate's plunderers: bands ride for a held farm, factions fight them
 * together, and the band is driven off or sacks the farm.
 */
class PlunderersTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    private Plunderers $plunderers;

    private Faction $holders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
        $this->season()->forceFill(['starts_at' => now()->subDay()])->save();
        $this->plunderers = app(Plunderers::class);
        $this->holders = $this->faction('Holders', $this->player(Species::Dog), $this->player());
        $this->holders->forceFill(['treasury' => 1000, 'materials' => ['wood' => 100]])->save();
        $this->hold($this->holders, 'thornbury');
    }

    public function test_a_band_rides_for_a_held_farm_in_daylight_and_the_valley_hears(): void
    {
        $band = $this->plunderers->muster($this->season());

        $this->assertNotNull($band);
        $this->assertSame($this->region('thornbury')->id, $band->region_id);
        $this->assertSame($this->holders->id, $band->faction_id);
        $this->assertGreaterThanOrEqual(Plunderers::MIN_MEN, $band->men);
        $this->assertStringContainsString('plunderers rode out of Irongate for Thornbury', Notice::query()->latest('id')->value('text'));

        // One band at a time, and a rest after it.
        $this->assertNull($this->plunderers->muster($this->season()));
        $band->forceFill(['resolved_at' => now(), 'won' => true])->save();
        $this->assertNull($this->plunderers->muster($this->season()));
        $this->travel(Plunderers::EVERY_HOURS + 1)->hours();
        $this->assertNotNull($this->plunderers->muster($this->season()));
    }

    public function test_no_band_rides_at_night_or_with_no_farm_held(): void
    {
        $this->travelTo(Clock::now()->setTime(23, 0));
        $this->assertNull($this->plunderers->muster($this->season()));

        $this->travelTo(Clock::now()->setTime(12, 0));
        $this->region('thornbury')->forceFill(['faction_id' => null])->save();
        $this->assertNull($this->plunderers->muster($this->season()));
    }

    public function test_factions_fight_together_and_share_the_haul(): void
    {
        $band = $this->plunderers->muster($this->season());
        $band->forceFill(['men' => 4])->save();
        $holder = $this->player(Species::Dog, attributes: ['corn' => 0]);
        $holder->forceFill(['faction_id' => $this->holders->id])->save();
        $ally = $this->player(Species::Horse, attributes: ['corn' => 0]);
        $this->faction('Friends', $ally);

        $this->plunderers->fight($holder->fresh(), $band, 3);
        $this->plunderers->fight($ally->fresh(), $band, 3);

        try {
            $this->plunderers->fight($this->player(), $band, 1);
            $this->fail('Lone wolves stay out of it.');
        } catch (GameException $e) {
            $this->assertStringContainsString('Factions fight the plunderers', $e->getMessage());
        }

        $this->travel(Plunderers::MARCH_MINUTES + 1)->minutes();
        $this->plunderers->resolveDue($this->season());

        $band->refresh();
        $this->assertTrue($band->won);
        $haul = 4 * Plunderers::CORN_PER_MAN;
        $this->assertSame((int) floor($haul / 2), $holder->fresh()->corn);
        $this->assertSame((int) floor($haul / 2), $ally->fresh()->corn);
        $this->assertSame(1000, $this->holders->fresh()->treasury);
    }

    public function test_a_band_nobody_beats_sacks_the_farm_and_lays_its_fighters_up(): void
    {
        $band = $this->plunderers->muster($this->season());
        $band->forceFill(['men' => 20])->save();
        $fighter = $this->player(Species::Pig);
        $this->faction('Brave', $fighter);
        $this->plunderers->fight($fighter->fresh(), $band, 1);

        $this->travel(Plunderers::MARCH_MINUTES + 1)->minutes();
        $this->plunderers->resolveDue($this->season());

        $this->assertFalse($band->fresh()->won);
        $this->assertSame(1000 - (int) floor(1000 * Plunderers::SACK_SHARE), $this->holders->fresh()->treasury);
        $this->assertSame(85, $this->holders->fresh()->materials['wood']);
        $this->assertTrue($fighter->fresh()->inPound());
    }

    public function test_a_house_with_an_irongate_pact_takes_a_cut_of_the_sack(): void
    {
        $ruler = $this->player(Species::Pig);
        $house = $this->faction('The House', $ruler);
        $this->hold($house, 'farmhouse');
        $house->forceFill(['treasury' => 0, 'materials' => []])->save();
        app(Wall::class)->setLaw($ruler->fresh(), 1, LawTemplate::IrongatePact, 40);

        $band = $this->plunderers->muster($this->season());
        $band->forceFill(['men' => 20, 'region_id' => $this->region('thornbury')->id])->save();
        $this->travel(Plunderers::MARCH_MINUTES + 1)->minutes();
        $this->plunderers->resolveDue($this->season());

        $sacked = (int) floor(1000 * Plunderers::SACK_SHARE);
        $this->assertSame((int) floor($sacked * 0.4), $house->fresh()->treasury);
        $this->assertSame(6, $house->fresh()->material('wood'));
        $this->assertTrue(Notice::query()->where('player_id', $ruler->id)->where('text', 'like', '%pact with Irongate paid off%')->exists());
        $this->assertSame(16, LawTemplate::IrongatePact->unrest(40, null));

        // A secret: the House and goats see it on the Wall; everyone else sees an empty place, but feels the unrest.
        $goat = $this->player(Species::Goat);
        $sheep = $this->player(Species::Sheep);
        $this->actingAs($ruler->user)->get(route('play.wall'))->assertInertia(fn (Assert $page) => $page->where('laws.0.template', 'irongate_pact')->where('laws.0.secret', true));
        $this->actingAs($goat->user)->get(route('play.wall'))->assertInertia(fn (Assert $page) => $page->where('laws.0.template', 'irongate_pact'));
        $this->actingAs($sheep->user)->get(route('play.wall'))->assertInertia(fn (Assert $page) => $page->missing('laws.0.template')->where('unrest', fn ($unrest) => $unrest >= 16));
        $this->assertFalse(Notice::query()->where('player_id', $this->holders->members()->first()->id)->where('text', 'like', '%Painted in the dark%')->exists());
        $this->assertTrue(Notice::query()->where('player_id', $ruler->id)->where('text', 'like', '%Painted in the dark%')->exists());
    }

    public function test_a_hospital_gets_beaten_fighters_up_sooner(): void
    {
        $band = $this->plunderers->muster($this->season());
        $band->forceFill(['men' => 20])->save();
        $medic = $this->player(Species::Pig);
        $medics = $this->faction('Medics', $medic);
        $this->hold($medics, 'brookside');
        Building::create(['faction_id' => $medics->id, 'region_id' => $this->region('brookside')->id, 'kind' => BuildingKind::Hospital, 'level' => 3, 'ready_at' => now()->subMinute()]);
        $this->plunderers->fight($medic->fresh(), $band, 1);

        $this->travel(Plunderers::MARCH_MINUTES + 1)->minutes();
        $this->plunderers->resolveDue($this->season());

        $minutes = (int) round(now()->diffInMinutes($medic->fresh()->pound_until));
        $this->assertSame((int) round(Plunderers::LAID_UP_MINUTES * 0.45), $minutes);
    }

    public function test_the_map_shows_the_band_riding(): void
    {
        $band = $this->plunderers->muster($this->season());
        $animal = Player::query()->where('faction_id', $this->holders->id)->first();

        $this->actingAs($animal->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('plunderers.id', $band->id)
            ->where('plunderers.region', 'thornbury')
            ->where('plunderers.men', $band->men));

        $this->actingAs($animal->user)->post(route('play.plunderers.fight', $band), ['horseshoes' => 2])->assertRedirect();
        $this->assertSame(2, $band->fighters()->value('horseshoes'));
    }

    public function test_seeded_targets_spread_over_held_farms(): void
    {
        $this->hold($this->holders, 'brookside');
        $seed = 1;
        while (Dice::roll($seed, 'target') < 0.5) {
            $seed++;
        }

        // Farms are picked in id order: a high roll takes the later one.
        $later = max($this->region('brookside')->id, $this->region('thornbury')->id);
        $this->assertSame($later, $this->plunderers->muster($this->season(), $seed)->region_id);
        $this->assertSame(1, PlunderBand::query()->count());
    }
}
