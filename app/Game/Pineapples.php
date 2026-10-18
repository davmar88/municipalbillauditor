<?php

namespace App\Game;

use App\Models\PineappleEntry;
use App\Models\Player;
use App\Payments\Packs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pineapples: what real money buys. They do one thing, swap for packs of
 * Apples, so what an Apple costs in money takes two steps to work out.
 * Every Pineapple earned or spent is a ledger row with a unique reference,
 * so the balance always matches and nothing pays twice.
 */
final class Pineapples
{
    /** @return bool Whether this award was new. */
    public static function award(Player $player, int $amount, string $reason, string $reference): bool
    {
        if ($amount <= 0) {
            return false;
        }

        return DB::transaction(function () use ($player, $amount, $reason, $reference) {
            if (PineappleEntry::query()->where('reference', $reference)->exists()) {
                return false;
            }

            PineappleEntry::create([
                'player_id' => $player->id,
                'amount' => $amount,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            Player::query()->whereKey($player->id)->increment('pineapples', $amount);
            $player->pineapples += $amount;

            return true;
        });
    }

    /**
     * Spend an animal's Pineapples, or say how many they have.
     *
     * @throws GameException
     */
    public static function spend(Player $player, int $amount, string $reason, string $reference): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($player, $amount, $reason, $reference) {
            $balance = (int) Player::query()->whereKey($player->id)->lockForUpdate()->value('pineapples');

            if ($balance < $amount) {
                throw new GameException($balance === 1 ? 'You have 1 Pineapple.' : 'You have '.number_format($balance).' Pineapples.');
            }

            PineappleEntry::create([
                'player_id' => $player->id,
                'amount' => -$amount,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            Player::query()->whereKey($player->id)->decrement('pineapples', $amount);
            $player->pineapples = $balance - $amount;
        });
    }

    /**
     * Swap Pineapples for a pack of Apples.
     *
     * @return array{id: string, apples: int, pineapples: int, save: int}
     *
     * @throws GameException
     */
    public static function swap(Player $player, string $packId): array
    {
        $pack = Packs::findApples($packId) ?? throw new GameException('Pick one of the Apple packs.');

        DB::transaction(function () use ($player, $pack) {
            // Both ledgers carry the same reference, so a swap can be traced either way.
            $reference = 'exchange:'.Str::lower((string) Str::ulid());
            self::spend($player, $pack['pineapples'], "Swapped for {$pack['apples']} Apples", $reference);
            Apples::award($player, $pack['apples'], 'Swapped '.number_format($pack['pineapples']).' Pineapples', $reference);
        });

        return $pack;
    }
}
