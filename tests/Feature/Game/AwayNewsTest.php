<?php

namespace Tests\Feature\Game;

use App\Game\AwayNews;
use App\Game\Notices;
use App\Mail\AwayNews as AwayNewsMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AwayNewsTest extends TestCase
{
    use MakesPlayers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeValley();
    }

    public function test_an_away_player_gets_news_of_what_happened_at_most_every_few_hours(): void
    {
        Mail::fake();
        $away = $this->player();
        $away->forceFill(['last_seen_at' => now()->subHour()])->save();
        $here = $this->player();
        $here->forceFill(['last_seen_at' => now()])->save();

        Notices::send([$away->id, $here->id], 'crime', '💰 Bramble robbed you of 20 Corn.');
        Notices::send($away->id, 'faction', 'Someone joined your faction.');

        $this->assertSame(1, app(AwayNews::class)->sendDue());
        Mail::assertQueued(AwayNewsMail::class, fn (AwayNewsMail $mail) => $mail->hasTo($away->user->email)
            && $mail->news === ['💰 Bramble robbed you of 20 Corn.']);
        $this->assertStringContainsString('robbed you', (new AwayNewsMail($away->fresh(), ['💰 Bramble robbed you of 20 Corn.']))->render());

        // Nothing more for a few hours, however much happens.
        Notices::send($away->id, 'battle', 'Your faction took Hayfield.');
        $this->assertSame(0, app(AwayNews::class)->sendDue());
        $this->travel(AwayNews::EVERY_HOURS + 1)->hours();
        $this->assertSame(1, app(AwayNews::class)->sendDue());
    }

    public function test_the_news_can_be_turned_off_from_the_email_or_the_me_page(): void
    {
        Mail::fake();
        $player = $this->player();

        $this->get(URL::signedRoute('news.off', ['player' => $player->id]))->assertRedirect(route('home'));
        $this->assertFalse($player->fresh()->news_emails);
        $this->get(route('news.off', ['player' => $player->id]))->assertForbidden();

        $this->actingAs($player->user)->put(route('play.me.news'), ['on' => true])->assertRedirect();
        $this->assertTrue($player->fresh()->news_emails);
        $this->actingAs($player->user)->put(route('play.me.news'), ['on' => false]);

        $player->forceFill(['last_seen_at' => now()->subHour()])->save();
        Notices::send($player->id, 'crime', 'Someone robbed you.');
        $this->assertSame(0, app(AwayNews::class)->sendDue());
    }
}
