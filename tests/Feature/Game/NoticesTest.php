<?php

namespace Tests\Feature\Game;

use App\Enums\CrimeKind;
use App\Enums\EntryMode;
use App\Enums\ItemTemplate;
use App\Enums\Species;
use App\Game\Battles;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Dice;
use App\Game\Factions;
use App\Game\LawTemplate;
use App\Game\Market;
use App\Game\Messages;
use App\Game\Wall;
use App\Models\Crime;
use App\Models\DirectMessage;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Notice;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bell and the pulse: who hears what as it happens, pop-ups for new
 * messages and news, and the chat window you open from a name.
 */
class NoticesTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        $this->travelTo(Clock::now()->setTime(12, 0));
    }

    /** @return array<int, string> */
    private function news(Player $player): array
    {
        return Notice::query()->where('player_id', $player->id)->orderBy('id')->pluck('text')->all();
    }

    public function test_a_raid_warns_the_defenders_and_rallies_the_attackers(): void
    {
        $red = [$this->player(Species::Horse), $this->player(Species::Horse), $this->player(Species::Horse)];
        $this->faction('Red Hooves', ...$red);
        $blue = $this->player();
        $blueFaction = $this->faction('Blue Guard', $blue);
        $this->hold($blueFaction, 'henhouse');

        $attack = app(Battles::class)->launch($red[0], $this->region('henhouse'), 1);

        $this->assertStringContainsString('Red Hooves is marching on Henhouse', $this->news($blue)[0]);
        $this->assertStringContainsString("{$red[0]->name} is leading a raid on Henhouse", $this->news($red[1])[0]);
        $this->assertSame([], $this->news($red[0]), 'The leader of the raid needs no reminder.');

        $this->travel(61)->minutes();
        app(Battles::class)->resolveDue($this->season());

        $this->assertSame('🏆 Your faction took Henhouse from Blue Guard.', $this->news($red[1])[1]);
        $this->assertSame('Henhouse fell to Red Hooves.', $this->news($blue)[1]);
        $this->assertSame('attack', $attack->fresh()->winner);
    }

    public function test_a_sale_tells_the_seller_and_the_maker(): void
    {
        $maker = $this->player(level: 2);
        $seller = $this->player(level: 2);
        $buyer = $this->player(attributes: ['corn' => 500]);
        $design = ItemDesign::create(['player_id' => $maker->id, 'template' => ItemTemplate::Hat, 'name' => 'Violet Hat', 'colours' => ['#b39aff', '#41d1ff'], 'pattern' => 'plain', 'status' => 'approved']);
        $item = Item::create(['item_design_id' => $design->id, 'player_id' => $seller->id, 'serial' => 7]);
        $listing = app(Market::class)->list($seller, $item, 100);

        app(Market::class)->buy($buyer, $listing);

        $this->assertSame(["🛒 {$buyer->name} bought your Violet Hat #7 for 100 Corn."], $this->news($seller));
        $this->assertSame(['Your design Violet Hat was resold. You earned 5 Corn.'], $this->news($maker));
    }

    public function test_requests_to_join_reach_the_leader_and_the_answer_reaches_the_asker(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Picky', $leader);
        $faction->forceFill(['entry_mode' => EntryMode::Approval])->save();
        $asker = $this->player();

        app(Factions::class)->join($asker, $faction->fresh());
        $this->assertSame(["{$asker->name} asks to join Picky."], $this->news($leader));

        app(Factions::class)->answer($leader->fresh(), $faction->joinRequests()->sole(), true);
        $this->assertSame(["You're in: Picky let you join."], $this->news($asker));
    }

    public function test_a_crime_tells_the_criminal_and_the_victims(): void
    {
        $wolf = $this->player(level: 3);
        $guard = $this->player();
        $faction = $this->faction('Vault', $guard);
        $faction->forceFill(['treasury' => 500])->save();
        $crimes = app(Crimes::class);

        $crime = $crimes->commit($wolf, CrimeKind::Loot, $faction->fresh());
        $crime->forceFill(['seed' => $this->seedThatSlipsAway($crimes, $crime)])->save();
        $this->travel(31)->minutes();
        $crimes->resolveDue($this->season());

        $this->assertSame(['💰 You got away with 50 Corn.'], $this->news($wolf));
        $this->assertSame(["🔥 A lone wolf looted 50 Corn from your faction's treasury."], $this->news($guard));
    }

    public function test_a_new_law_is_news_for_the_whole_valley(): void
    {
        $ruler = $this->player();
        $house = $this->faction('The House', $ruler);
        $this->hold($house, 'farmhouse');
        $bystander = $this->player();

        app(Wall::class)->setLaw($ruler->fresh(), 1, LawTemplate::Curfew, 3);

        $this->assertSame(['📜 New on the Barn Wall: No raids on House land for 3 hours after 22:00.'], $this->news($bystander));
    }

    public function test_the_pulse_brings_new_messages_and_news_and_the_bell_reads_them(): void
    {
        $me = $this->player();
        $friend = $this->player();

        $first = $this->actingAs($me->user)->getJson(route('play.pulse'))->assertOk();
        $this->assertSame([], $first->json('messages'), 'The first check only learns where things stand.');

        app(Messages::class)->send($friend, $me, 'Meet me at the Stables');
        Notice::create(['player_id' => $me->id, 'kind' => 'sale', 'text' => 'Sold!', 'url' => '/play/market']);

        $this->actingAs($me->user)
            ->getJson(route('play.pulse', ['messages' => $first->json('latestMessage'), 'notices' => $first->json('latestNotice')]))
            ->assertOk()
            ->assertJsonPath('unreadMessages', 1)
            ->assertJsonPath('unreadNotices', 1)
            ->assertJsonPath('messages.0.from.name', $friend->name)
            ->assertJsonPath('messages.0.body', 'Meet me at the Stables')
            ->assertJsonPath('notices.0.text', 'Sold!');

        $this->actingAs($me->user)->getJson(route('play.notices'))->assertJsonPath('unread', 1)->assertJsonPath('notices.0.text', 'Sold!');
        $this->actingAs($me->user)->postJson(route('play.notices.read'))->assertJsonPath('unread', 0);
        $this->assertSame(0, Notice::query()->whereNull('read_at')->count());
    }

    public function test_the_chat_window_talks_and_reads(): void
    {
        $me = $this->player();
        $vladimir = $this->player(attributes: ['name' => 'Vladimir']);
        app(Messages::class)->send($vladimir, $me, 'Hello from the Stables');

        $this->actingAs($me->user)->getJson(route('play.talk', $vladimir))
            ->assertOk()
            ->assertJsonPath('animal.name', 'Vladimir')
            ->assertJsonPath('messages.0.body', 'Hello from the Stables')
            ->assertJsonPath('cantWrite', null);
        $this->assertNotNull(DirectMessage::query()->sole()->read_at, 'Opening the window reads it.');

        $this->actingAs($me->user)->postJson(route('play.talk.say', $vladimir), ['body' => 'On my way'])
            ->assertOk()
            ->assertJsonPath('messages.1.body', 'On my way')
            ->assertJsonPath('messages.1.mine', true);

        $this->actingAs($me->user)->getJson(route('play.talk', $me))->assertNotFound();
    }

    /** A seed whose roll gets the criminal away, so the test doesn't hang on luck. */
    private function seedThatSlipsAway(Crimes $crimes, Crime $crime): int
    {
        $chance = $crimes->catchChance($this->season(), $crime);

        foreach (range(1, 1000) as $seed) {
            if (Dice::roll($seed, 'caught') >= $chance) {
                return $seed;
            }
        }

        $this->fail('No seed got away.');
    }
}
