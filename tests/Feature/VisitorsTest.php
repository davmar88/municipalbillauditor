<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visit;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VisitorsTest extends TestCase
{
    use RefreshDatabase;

    private function southAfricaIsHome(): void
    {
        DB::table('ip_countries')->insert([
            ['ip_from' => inet_pton('127.0.0.0'), 'ip_to' => inet_pton('127.255.255.255'), 'country' => 'ZA'],
            ['ip_from' => inet_pton('8.8.8.0'), 'ip_to' => inet_pton('8.8.8.255'), 'country' => 'US'],
        ]);
    }

    public function test_a_visit_is_noted_with_its_country_but_never_its_address(): void
    {
        $this->southAfricaIsHome();

        $this->get(route('home'))->assertOk();

        $visit = Visit::sole();
        $this->assertSame('ZA', $visit->country);
        $this->assertSame('home', $visit->route);
        $this->assertSame('/', $visit->path);
        $this->assertNull($visit->user_id);
        $this->assertStringNotContainsString('127.0.0.1', json_encode($visit->getAttributes()));
        $this->assertSame(16, strlen($visit->visitor));

        $this->assertSame('US', Geo::country('8.8.8.8'));
        $this->assertNull(Geo::country('10.1.2.3'));
        $this->assertSame('🇿🇦', Geo::flag('ZA'));
    }

    public function test_robots_background_fetches_and_admin_pages_are_not_counted(): void
    {
        $this->withHeader('User-Agent', 'Googlebot/2.1')->get(route('home'));
        $this->withHeader('User-Agent', 'Mozilla/5.0')->withHeader('X-Inertia-Partial-Data', 'strikes')->get(route('home'));

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->withHeader('User-Agent', 'Mozilla/5.0')->get(route('admin.visitors'))->assertOk();

        $this->assertSame(0, Visit::query()->count());
    }

    public function test_the_owner_sees_visitors_countries_pages_and_who_did_what(): void
    {
        $this->southAfricaIsHome();
        $player = User::factory()->create();
        $this->withHeader('User-Agent', 'Mozilla/5.0')->get(route('home'));
        $this->actingAs($player)->withHeader('User-Agent', 'Mozilla/5.0')->get(route('wiki'));

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.visitors', ['days' => 7]))->assertInertia(fn (Assert $page) => $page
            ->component('admin/visitors')
            ->where('totals.views', 2)
            ->where('totals.players', 1)
            // Sign-ups come from the accounts made, Google ones included: the player and the admin.
            ->where('totals.signups', 2)
            ->where('countries.0.code', 'ZA')
            ->where('countries.0.name', 'South Africa')
            ->where('recent.0.who', $player->name)
            ->where('recent.0.what', 'How the game works')
            ->where('recent.1.who', null)
            ->where('geoReady', true));

        // Just one player's doings.
        $this->actingAs($admin)->get(route('admin.visitors', ['user' => $player->id]))->assertInertia(fn (Assert $page) => $page
            ->where('filteredBy', $player->name)
            ->has('recent', 1));

        // Only the owner.
        $this->actingAs($player)->get(route('admin.visitors'))->assertForbidden();
    }

    public function test_the_country_list_loads_from_db_ip(): void
    {
        $csv = "1.0.0.0,1.0.0.255,AU\n2c0f:f000::,2c0f:ffff:ffff:ffff:ffff:ffff:ffff:ffff,ZA\nnot,a,row\n";
        Http::fake(['download.db-ip.com/*' => Http::response(gzencode($csv))]);

        $this->artisan('geo:update')->assertSuccessful();

        $this->assertSame(2, DB::table('ip_countries')->count());
        $this->assertSame('AU', Geo::country('1.0.0.7'));
        $this->assertSame('ZA', Geo::country('2c0f:f001::1'));
    }
}
