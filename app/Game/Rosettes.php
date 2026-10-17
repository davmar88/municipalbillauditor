<?php

namespace App\Game;

use App\Models\Player;
use App\Models\RosetteEntry;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * Profile points. Every award is a ledger row with a unique reference, so
 * the same deed never pays twice, and the player's total always matches.
 */
final class Rosettes
{
    /** @return bool Whether this award was new. */
    public static function award(Player $player, int $amount, string $reason, string $reference, ?Season $season = null): bool
    {
        if ($amount === 0) {
            return false;
        }

        return DB::transaction(function () use ($player, $amount, $reason, $reference, $season) {
            if (RosetteEntry::query()->where('reference', $reference)->exists()) {
                return false;
            }

            RosetteEntry::create([
                'player_id' => $player->id,
                'season_id' => $season?->id,
                'amount' => $amount,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            Player::query()->whereKey($player->id)->increment('rosettes', $amount);
            $player->rosettes += $amount;

            return true;
        });
    }

    /** Rosettes a player earned today for one kind of deed. */
    public static function earnedToday(Player $player, string $referencePrefix): int
    {
        return (int) RosetteEntry::query()
            ->where('player_id', $player->id)
            ->where('reference', 'like', $referencePrefix.'%')
            ->where('created_at', '>=', Clock::startOfToday())
            ->sum('amount');
    }
}
