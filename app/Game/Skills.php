<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Season;
use App\Models\SkillEntry;
use Illuminate\Support\Facades\DB;

/**
 * The two skills players are ranked on. Market grows with every sale and
 * purchase in the shops; Creativity with designs that pass their check and
 * with every copy of your design that sells, yours or resold. Every award is
 * a ledger row with a unique reference, so seasons can be ranked on their own.
 */
final class Skills
{
    public const MARKET = 'market';

    public const CREATIVITY = 'creativity';

    public const ALL = [self::MARKET, self::CREATIVITY];

    /** @return bool Whether this award was new. */
    public static function award(int $playerId, string $skill, int $amount, string $reason, string $reference, ?Season $season = null): bool
    {
        if ($amount <= 0 || ! in_array($skill, self::ALL, true)) {
            return false;
        }

        // A Honey Cake doubles what is earned while it works.
        $amount *= Powers::skills($playerId);

        return DB::transaction(function () use ($playerId, $skill, $amount, $reason, $reference, $season) {
            if (SkillEntry::query()->where('reference', $reference)->exists()) {
                return false;
            }

            SkillEntry::create([
                'player_id' => $playerId,
                'season_id' => $season?->id,
                'skill' => $skill,
                'amount' => $amount,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            Player::query()->whereKey($playerId)->increment("{$skill}_skill", $amount);

            return true;
        });
    }

    /** A skill's level: level 2 at 30 points, and each level after needs 30 more than the last. */
    public static function level(int $points): int
    {
        $level = 1;

        while (self::threshold($level + 1) <= $points) {
            $level++;
        }

        return $level;
    }

    public static function threshold(int $level): int
    {
        return 15 * $level * ($level - 1);
    }

    /** What a level is called, so a rank reads like a title. */
    public static function title(string $skill, int $level): string
    {
        $titles = $skill === self::MARKET
            ? ['Stall hand', 'Trader', 'Dealer', 'Merchant', 'Broker', 'Market baron']
            : ['Doodler', 'Maker', 'Designer', 'Artisan', 'Master maker', 'Legend of the Workshop'];

        return $titles[min(count($titles) - 1, intdiv($level - 1, 3))];
    }

    /**
     * A player's skill for the screens: points, level, title and how far to the next level.
     *
     * @return array{skill: string, points: int, level: int, title: string, from: int, to: int}
     */
    public static function card(Player $player, string $skill): array
    {
        $points = (int) $player->{"{$skill}_skill"};
        $level = self::level($points);

        return [
            'skill' => $skill,
            'points' => $points,
            'level' => $level,
            'title' => self::title($skill, $level),
            'from' => self::threshold($level),
            'to' => self::threshold($level + 1),
        ];
    }
}
