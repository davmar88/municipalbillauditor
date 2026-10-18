<?php

namespace App\Game;

use App\Models\AppleEntry;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apples, the game's premium currency, bought in packs with Pineapples.
 * Every Apple earned or spent is a ledger row with a unique reference, so
 * the balance always matches and nothing pays twice. The game never hands
 * Apples out free: they come from packs, and from other animals.
 */
final class Apples
{
    public static function cornPerApple(): int
    {
        return (int) config('game.apples.corn_per_apple');
    }

    /**
     * Swap Apples for Corn. It only goes this way: Corn never turns back
     * into Apples.
     *
     * @return int The Corn the animal got.
     *
     * @throws GameException
     */
    public static function swapForCorn(Player $player, int $apples): int
    {
        if ($apples < 1) {
            throw new GameException('Swap at least 1 Apple.');
        }

        $corn = $apples * self::cornPerApple();

        DB::transaction(function () use ($player, $apples, $corn) {
            self::spend($player, $apples, "Swapped for {$corn} Corn", 'swap:'.Str::uuid());
            Player::query()->whereKey($player->id)->increment('corn', $corn);
            $player->corn += $corn;
        });

        return $corn;
    }

    /** @return bool Whether this award was new. */
    public static function award(Player $player, int $amount, string $reason, string $reference): bool
    {
        if ($amount <= 0) {
            return false;
        }

        return DB::transaction(function () use ($player, $amount, $reason, $reference) {
            if (AppleEntry::query()->where('reference', $reference)->exists()) {
                return false;
            }

            AppleEntry::create([
                'player_id' => $player->id,
                'amount' => $amount,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            Player::query()->whereKey($player->id)->increment('apples', $amount);
            $player->apples += $amount;

            return true;
        });
    }

    /**
     * Spend an animal's Apples, or say how many they have.
     *
     * @throws GameException
     */
    public static function spend(Player $player, int $amount, string $reason, string $reference): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($player, $amount, $reason, $reference) {
            $balance = (int) Player::query()->whereKey($player->id)->lockForUpdate()->value('apples');

            if ($balance < $amount) {
                throw new GameException($balance === 1 ? 'You have 1 Apple.' : "You have {$balance} Apples.");
            }

            AppleEntry::create([
                'player_id' => $player->id,
                'amount' => -$amount,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            Player::query()->whereKey($player->id)->decrement('apples', $amount);
            $player->apples = $balance - $amount;
        });
    }
}
