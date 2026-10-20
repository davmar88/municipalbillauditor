<?php

namespace App\Game;

use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every action costs horseshoes. A player holds up to 50, and one comes
 * back every 45 minutes. Extra ones handed out on top are kept until spent. They are worked out when read, so nothing has to
 * run on a timer.
 */
final class Horseshoes
{
    public static function max(): int
    {
        return (int) config('game.horseshoes.max');
    }

    private static function minutes(): int
    {
        return (int) config('game.horseshoes.refill_minutes');
    }

    /** @return array{0: int, 1: CarbonImmutable} The count now, and the clock the next one runs from. */
    private static function settle(Player $player): array
    {
        $now = CarbonImmutable::now();

        // Extra horseshoes handed out above the usual most are kept until spent; none come back meanwhile.
        if ($player->horseshoes >= self::max()) {
            return [$player->horseshoes, $now];
        }

        $at = CarbonImmutable::instance($player->horseshoes_at);
        $gained = intdiv(max(0, (int) $at->diffInMinutes($now)), self::minutes());
        $count = min(self::max(), $player->horseshoes + $gained);

        return [$count, $count >= self::max() ? $now : $at->addMinutes($gained * self::minutes())];
    }

    public static function current(Player $player): int
    {
        return self::settle($player)[0];
    }

    /** Seconds until the next horseshoe, or null when the player is full. */
    public static function nextIn(Player $player): ?int
    {
        [$count, $clock] = self::settle($player);

        if ($count >= self::max()) {
            return null;
        }

        return max(0, (int) CarbonImmutable::now()->diffInSeconds($clock->addMinutes(self::minutes())));
    }

    /**
     * Take horseshoes from a player whose row is already locked.
     *
     * @throws GameException
     */
    public static function spend(Player $player, int $count): void
    {
        [$current, $clock] = self::settle($player);

        if ($current < $count) {
            throw new GameException($count === 1
                ? 'You have no horseshoes left. One comes back every 45 minutes.'
                : "That needs {$count} horseshoes and you have {$current}. One comes back every 45 minutes.");
        }

        $player->horseshoes = $current - $count;
        $player->horseshoes_at = $current >= self::max() ? CarbonImmutable::now() : $clock;
        $player->save();
    }

    /** Give horseshoes to a player whose row is already locked, up to the most they can hold. */
    public static function give(Player $player, int $count): void
    {
        [$current, $clock] = self::settle($player);

        $player->horseshoes = max($current, min(self::max(), $current + max(0, $count)));
        $player->horseshoes_at = $player->horseshoes >= self::max() ? CarbonImmutable::now() : $clock;
        $player->save();
    }

    /** @return array<int, int> Horseshoes on sale for Apples, by how many: count => Apples. */
    public static function packs(): array
    {
        return array_map('intval', (array) config('game.horseshoes.packs'));
    }

    /** The most an animal can hold, bought ones included. */
    public static function saddlebag(): int
    {
        return (int) config('game.horseshoes.saddlebag');
    }

    /**
     * Buy horseshoes with Apples, for an animal who doesn't want to wait.
     * They stack past the usual most, up to what a saddlebag holds.
     *
     * @throws GameException
     */
    public static function buy(Player $player, int $count): int
    {
        $apples = self::packs()[$count] ?? throw new GameException('Pick one of the horseshoe packs.');

        return DB::transaction(function () use ($player, $count, $apples) {
            $player = Players::lock($player);
            $current = self::current($player);

            if ($current + $count > self::saddlebag()) {
                throw new GameException('Your saddlebag holds '.self::saddlebag()." horseshoes and you have {$current}. Spend some first.");
            }

            Apples::spend($player, $apples, "Bought {$count} horseshoes", "horseshoes:{$player->id}:".Str::uuid());
            self::bonus($player->fresh(), $count);

            return self::current($player->fresh());
        });
    }

    /** Hand every player extra horseshoes on top of what they have, past the usual most. */
    public static function bonus(Player $player, int $count): void
    {
        $player->horseshoes = self::current($player) + max(0, $count);
        $player->horseshoes_at = CarbonImmutable::now();
        $player->save();
    }
}
