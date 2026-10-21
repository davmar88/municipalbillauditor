<?php

namespace App\Game;

use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The daily streak: come back day after day and each visit pays a little
 * more, up to a big seventh day; then the week starts again. Miss a day and
 * the streak starts over.
 */
final class Streaks
{
    /** What each day of the week-long streak pays. */
    public const REWARDS = [
        1 => ['corn' => 20],
        2 => ['corn' => 40],
        3 => ['horseshoes' => 5],
        4 => ['corn' => 60],
        5 => ['horseshoes' => 10],
        6 => ['corn' => 80],
        7 => ['corn' => 150, 'rosettes' => 15],
    ];

    /** Count today's visit and pay the day's reward, once. Returns the streak, or null when today was already counted. */
    public function count(Player $player): ?int
    {
        $today = Clock::today();
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

        return DB::transaction(function () use ($player, $today, $yesterday) {
            $player = Player::query()->lockForUpdate()->findOrFail($player->id);
            $last = $player->streak_on?->toDateString();

            if ($last === $today) {
                return null;
            }

            $days = $last === $yesterday ? $player->streak_days + 1 : 1;
            $reward = self::REWARDS[(($days - 1) % 7) + 1];
            $player->forceFill([
                'streak_days' => $days,
                'streak_on' => $today,
                'corn' => $player->corn + ($reward['corn'] ?? 0),
            ])->save();

            if (isset($reward['horseshoes'])) {
                // Stacks on top, but never past what a saddlebag holds.
                Horseshoes::bonus($player, min($reward['horseshoes'], max(0, Horseshoes::saddlebag() - Horseshoes::current($player))));
            }

            if (isset($reward['rosettes'])) {
                Rosettes::award($player, $reward['rosettes'], "A {$days}-day streak", "streak:{$player->id}:{$today}", $player->valley->currentSeason());
            }

            $next = self::REWARDS[($days % 7) + 1];
            Notices::send($player->id, 'streak', '🔥 '.($days === 1 ? 'Day 1 of your streak' : "{$days} days in a row").': '.self::describe($reward).'. Come back tomorrow for '.self::describe($next).'.', route('play.today', [], false));

            return $days;
        });
    }

    /** @param  array<string, int>  $reward */
    public static function describe(array $reward): string
    {
        return collect($reward)->map(fn (int $amount, string $what) => "{$amount} ".match ($what) {
            'corn' => 'Corn',
            'horseshoes' => 'horseshoes',
            'rosettes' => 'Rosettes',
        })->implode(' and ');
    }
}
