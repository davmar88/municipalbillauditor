<?php

namespace App\Game;

use App\Mail\AwayNews as AwayNewsMail;
use App\Models\Notice;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * News by email for animals whose players are away: what happened to them
 * since they were last about, so they come back to deal with it. At most one
 * email every few hours, only the notices that matter, and never while the
 * player is on the site.
 */
final class AwayNews
{
    /** Away this long before news goes out, in minutes. */
    public const AWAY_MINUTES = 30;

    /** At most one email in this many hours. */
    public const EVERY_HOURS = 6;

    /** The notices worth an email: things done to you, or that need you. */
    public const KINDS = ['crime', 'battle', 'warrant', 'home', 'raid', 'house', 'death', 'gift', 'sale', 'farm', 'gate', 'uprising'];

    /** Send what is due. Returns how many emails went out. */
    public function sendDue(): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        Player::query()
            ->with('user:id,email,email_verified_at')
            ->where('news_emails', true)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now->subMinutes(self::AWAY_MINUTES)))
            ->where(fn ($query) => $query->whereNull('news_sent_at')->orWhere('news_sent_at', '<', $now->subHours(self::EVERY_HOURS)))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('notices')
                ->whereColumn('notices.player_id', 'players.id')
                ->whereNull('notices.read_at')
                ->whereIn('notices.kind', self::KINDS)
                ->where('notices.created_at', '>', $now->subDays(2)))
            ->limit(200)
            ->get()
            ->each(function (Player $player) use ($now, &$sent) {
                if ($player->user?->email_verified_at === null) {
                    return;
                }

                // Only what happened since they were last about, and since the last email.
                $since = collect([$player->last_seen_at, $player->news_sent_at, $now->subDays(2)])->filter()->max();
                $news = Notice::query()
                    ->where('player_id', $player->id)
                    ->whereNull('read_at')
                    ->whereIn('kind', self::KINDS)
                    ->where('created_at', '>', $since)
                    ->latest('id')
                    ->limit(8)
                    ->pluck('text')
                    ->all();

                if ($news === []) {
                    return;
                }

                $player->forceFill(['news_sent_at' => $now])->save();
                Mail::to($player->user)->queue(new AwayNewsMail($player, $news));
                $sent++;
            });

        return $sent;
    }
}
