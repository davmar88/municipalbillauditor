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
    /** What a Corn bounty pays the hunter who serves it: one Apple for every ten Corn. */
    public const CORN_PER_APPLE = 10;

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

    /** The Apples a bounty of Corn pays out. */
    public static function forBounty(int $corn): int
    {
        return intdiv($corn, self::CORN_PER_APPLE);
    }
}
