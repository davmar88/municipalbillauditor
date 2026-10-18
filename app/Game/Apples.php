<?php

namespace App\Game;

use App\Models\AppleEntry;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Apples, the game's premium currency. Every Apple earned is a ledger row
 * with a unique reference, so the balance always matches and nothing pays
 * twice. Earned Apples stay in the game: there is no cashing out.
 */
final class Apples
{
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
