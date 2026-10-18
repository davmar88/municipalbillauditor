<?php

namespace App\Game;

use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchard membership, bought with Apples a month at a time. Members get one
 * free horseshoe refill a day, a badge by their name and an extra shop
 * shelf. The Apples paid for it never come back out, so the game keeps them.
 */
final class Orchard
{
    public static function price(): int
    {
        return (int) config('game.orchard.price');
    }

    public static function days(): int
    {
        return (int) config('game.orchard.days');
    }

    /** Whether a member still has today's free refill, by valley time. */
    public static function refillReady(Player $player): bool
    {
        return $player->inOrchard() && $player->orchard_refill_on?->toDateString() !== Clock::today();
    }

    /**
     * Join, or add another month to a membership that's still running.
     *
     * @return array{until: CarbonImmutable, renewed: bool} When the membership now ends, and whether it was running already.
     *
     * @throws GameException
     */
    public static function join(Player $player): array
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $price = self::price();

            if ($player->apples < $price) {
                throw new GameException("Orchard membership costs {$price} Apples. You have {$player->apples}.");
            }

            Apples::spend($player, $price, 'Orchard membership for '.self::days().' days', 'orchard:'.Str::uuid());

            $renewed = $player->inOrchard();
            $from = $renewed ? CarbonImmutable::instance($player->orchard_until) : CarbonImmutable::now();
            $player->orchard_until = $from->addDays(self::days());
            $player->save();

            return ['until' => CarbonImmutable::instance($player->orchard_until), 'renewed' => $renewed];
        });
    }

    /**
     * A member's free refill for the day: horseshoes back to full.
     *
     * @throws GameException
     */
    public static function refill(Player $player): void
    {
        DB::transaction(function () use ($player) {
            $player = Players::lock($player);

            if (! $player->inOrchard()) {
                throw new GameException('The free daily refill is for Orchard members.');
            }

            if (! self::refillReady($player)) {
                throw new GameException("You've had today's free refill. The next one is ready tomorrow.");
            }

            if (Horseshoes::current($player) >= Horseshoes::max()) {
                throw new GameException('Your horseshoes are already full. Keep the refill for when you run low.');
            }

            $player->orchard_refill_on = Clock::today();
            Horseshoes::give($player, Horseshoes::max());
        });
    }
}
