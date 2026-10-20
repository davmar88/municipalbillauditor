<?php

namespace Tests\Feature\Game;

use App\Models\BugReport;
use App\Models\GameUpdate;
use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Bug reports with a photo or video, the owner reading them, and playing
 * as the player to see the bug.
 */
class BugReportsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
        Storage::fake('local');
    }

    public function test_a_player_reports_a_bug_with_a_video_and_the_owner_reads_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->player()->forceFill(['user_id' => $admin->id])->save();
        $player = $this->player();

        $this->actingAs($player->user)->post(route('play.bugs.store'), [
            'title' => 'Fight button does nothing',
            'description' => 'I tapped Fight them and nothing happened.',
            'page' => 'The map',
            'attachment' => UploadedFile::fake()->create('bug.mp4', 2048, 'video/mp4'),
        ])->assertRedirect();

        $report = BugReport::sole();
        $this->assertSame($player->id, $report->player_id);
        Storage::disk('local')->assertExists($report->attachment_path);
        $this->assertStringContainsString('Bug report #'.$report->id, Notice::query()->where('player_id', $admin->player->id)->value('text'));

        $this->actingAs($admin)->get(route('admin.bugs'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/bugs')
            ->has('reports', 1)
            ->where('reports.0.user.id', $player->user_id)
            ->where('reports.0.player.id', $player->id)
            ->where('reports.0.attachment.video', true));
        $this->actingAs($admin)->get(route('admin.bugs.attachment', $report))->assertOk();
        $this->actingAs($player->user)->get(route('admin.bugs.attachment', $report))->assertForbidden();

        $this->actingAs($admin)->put(route('admin.bugs.update', $report), ['status' => 'fixed', 'note' => 'Fixed in today\'s update.'])->assertRedirect();
        $this->actingAs($player->user)->get(route('play.bugs.create'))->assertInertia(fn (Assert $page) => $page
            ->where('mine.0.status', 'fixed')
            ->where('mine.0.note', 'Fixed in today\'s update.'));
    }

    public function test_an_owner_plays_as_a_player_and_comes_back(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $player = $this->player();

        $this->actingAs($admin)->post(route('admin.play-as', $player->user))->assertRedirect(route('play'));
        $this->assertAuthenticatedAs($player->user);
        $this->get(route('play.online'))->assertInertia(fn (Assert $page) => $page->where('impersonating', true));

        $this->post(route('play-as.stop'))->assertRedirect(route('admin.bugs'));
        $this->assertAuthenticatedAs($admin);

        // Other owners can't be played as, and players can't play as anyone.
        $this->actingAs($admin)->post(route('admin.play-as', User::factory()->create(['is_admin' => true])))->assertForbidden();
        $this->actingAs($player->user)->post(route('admin.play-as', $this->player()->user))->assertForbidden();
        $this->actingAs($player->user)->post(route('play-as.stop'))->assertForbidden();
    }

    public function test_the_updates_page_shows_what_the_owner_posts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Filled from Git on every deploy, once per commit.
        $this->artisan('game:changelog')->assertSuccessful();
        $imported = GameUpdate::query()->whereNotNull('commit')->count();
        $this->assertGreaterThan(0, $imported);
        $this->artisan('game:changelog')->assertSuccessful();
        $this->assertSame($imported, GameUpdate::query()->whereNotNull('commit')->count());
        $this->get(route('updates'))->assertOk()->assertSee('Updates and fixes')->assertSee('Add a skin shop for homes');

        $this->actingAs($admin)->post(route('admin.updates.store'), ['kind' => 'fix', 'title' => 'Plunderer banner stuck', 'body' => 'It now goes away when the fight ends.'])->assertRedirect();
        $this->get(route('updates'))->assertSee('Plunderer banner stuck');
        $this->actingAs($this->player()->user)->post(route('admin.updates.store'), ['kind' => 'new', 'title' => 'x', 'body' => 'y'])->assertForbidden();
    }

    public function test_a_player_reports_another_for_cheating_naming_the_rule(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reporter = $this->player();
        $cheat = $this->player();

        $this->actingAs($reporter->user)->get(route('play.bugs.create', ['player' => $cheat->id]))->assertInertia(fn (Assert $page) => $page
            ->where('reported.id', $cheat->id)
            ->has('rules', count(config('fairplay.rules'))));

        $this->actingAs($reporter->user)->post(route('play.bugs.store'), ['reported' => $cheat->id, 'title' => 'Two accounts', 'description' => 'Fights on both sides of every raid.'])
            ->assertSessionHasErrors('rule');
        $this->actingAs($reporter->user)->post(route('play.bugs.store'), ['reported' => $cheat->id, 'rule' => 'FP1', 'title' => 'Two accounts', 'description' => 'Fights on both sides of every raid.'])
            ->assertRedirect();

        $report = BugReport::sole();
        $this->assertSame(['cheat', $cheat->id, 'FP1'], [$report->kind, $report->reported_player_id, $report->rule]);
        $this->actingAs($admin)->get(route('admin.bugs', ['kind' => 'cheat']))->assertInertia(fn (Assert $page) => $page
            ->where('reports.0.reported.id', $cheat->id)
            ->where('reports.0.rule', 'FP1'));
        $this->get(route('fair-play'))->assertOk()->assertSee('FP1')->assertSee('No cheating');
        $this->get(route('wiki'))->assertOk()->assertSee('The Animal Farm wiki')->assertSee('Horseshoes');
    }
}
