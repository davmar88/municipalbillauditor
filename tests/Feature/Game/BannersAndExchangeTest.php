<?php

namespace Tests\Feature\Game;

use App\Enums\ItemTemplate;
use App\Game\Banners;
use App\Models\Item;
use App\Models\ItemDesign;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Faction banners, designed or uploaded; and the Market as an exchange. */
class BannersAndExchangeTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        Storage::fake('local');
    }

    public function test_a_leader_designs_or_uploads_the_banner_and_members_cannot(): void
    {
        $leader = $this->player();
        $member = $this->player();
        $faction = $this->faction('Flags', $leader, $member);

        $this->actingAs($leader->user)->put(route('play.faction.banner'), ['pattern' => 'chevron', 'colours' => ['#aa2222', '#f5ecd7'], 'emblem' => '🐷'])->assertRedirect();
        $banner = Banners::of($faction->fresh());
        $this->assertSame(['pattern', 'chevron', '🐷'], [$banner['mode'], $banner['pattern'], $banner['emblem']]);

        // A plain member can't change it.
        $this->actingAs($member->user)->put(route('play.faction.banner'), ['pattern' => 'plain', 'colours' => ['#000000', '#ffffff'], 'emblem' => '']);
        $this->assertSame('chevron', Banners::of($faction->fresh())['pattern']);

        $this->actingAs($leader->user)->post(route('play.faction.banner.upload'), ['image' => UploadedFile::fake()->image('banner.png', 300, 400)])->assertRedirect();
        $banner = Banners::of($faction->fresh());
        $this->assertSame('image', $banner['mode']);
        $this->get($banner['image'])->assertOk();

        $this->actingAs($leader->user)->get(route('play.faction'))->assertInertia(fn (Assert $page) => $page->where('faction.banner.mode', 'image'));

        // An owner can take an offensive picture down.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->delete(route('admin.banner.take-down', $faction))->assertRedirect();
        $this->assertSame('pattern', Banners::of($faction->fresh())['mode']);
    }

    public function test_the_exchange_shows_what_sells_and_what_is_about(): void
    {
        $maker = $this->player();
        $buyer = $this->player();
        $hat = ItemDesign::create(['player_id' => $maker->id, 'template' => ItemTemplate::Hat, 'name' => 'Red Hat', 'colours' => ['#aa2222', '#ffffff'], 'pattern' => 'plain', 'status' => 'approved']);
        $hat->forceFill(['made' => 3])->save();
        $fork = ItemDesign::create(['player_id' => $maker->id, 'template' => ItemTemplate::Pitchfork, 'name' => 'Old Fork', 'colours' => ['#888888', '#552200'], 'pattern' => 'plain', 'status' => 'approved']);
        $fork->forceFill(['made' => 2])->save();

        foreach ([1, 2, 3] as $serial) {
            $item = Item::create(['item_design_id' => $hat->id, 'player_id' => $buyer->id, 'serial' => $serial]);
            Listing::query()->forceCreate(['item_id' => $item->id, 'player_id' => $maker->id, 'price' => 10 + $serial, 'currency' => 'corn', 'buyer_player_id' => $buyer->id, 'sold_at' => now()->subDays($serial)]);
        }
        foreach ([1, 2] as $serial) {
            Item::create(['item_design_id' => $fork->id, 'player_id' => $maker->id, 'serial' => $serial]);
        }

        $this->actingAs($buyer->user)->get(route('play.exchange'))->assertInertia(fn (Assert $page) => $page
            ->component('game/exchange')
            ->where('totals.designs', 2)
            ->where('totals.circulating', 5)
            ->where('totals.sold', 3)
            ->where('most.0.name', 'Red Hat')
            ->where('least.0.name', 'Old Fork')
            ->where('kinds.0.kind', 'Hat'));
    }

    public function test_anyone_in_the_valley_sees_a_factions_page_and_the_map_flies_its_banner(): void
    {
        $leader = $this->player();
        $faction = $this->faction('Open Book', $leader, $this->player());
        $this->hold($faction, 'thornbury');
        $faction->forceFill(['banner' => ['mode' => 'pattern', 'pattern' => 'cross', 'colours' => ['#224488', '#ffffff'], 'emblem' => '⭐']])->save();
        $visitor = $this->player();

        $this->actingAs($visitor->user)->get(route('play.factions.show', $faction))->assertInertia(fn (Assert $page) => $page
            ->component('game/faction-profile')
            ->where('faction.name', 'Open Book')
            ->where('faction.banner.pattern', 'cross')
            ->has('members', 2)
            ->has('land', 1)
            ->where('faction.mine', false)
            ->missing('faction.treasury'));

        $this->actingAs($visitor->user)->get(route('play'))->assertInertia(fn (Assert $page) => $page
            ->where('regions', function ($regions) {
                $thornbury = collect($regions)->firstWhere('key', 'thornbury');
                $this->assertSame('⭐', $thornbury['faction']['banner']['emblem'] ?? null, json_encode($thornbury['faction'] ?? null));

                return true;
            }));
    }
}
