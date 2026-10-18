<?php

namespace App\Game;

use App\Models\AppleTree;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apple trees. An animal plants one with Pineapples; after a few days it
 * fruits, and grows Apples into its basket every day until it has given
 * them all. A full basket stops it growing until the animal comes back to
 * harvest, so nothing grown is ever lost, only delayed.
 *
 * A grown Apple costs less than a bought one but more than a cash-out pays
 * for it, so trees are the cheapest way to Apples and never a way to make
 * money.
 */
final class Trees
{
    /** What a tree costs to plant, in Pineapples. */
    public static function price(): int
    {
        return (int) config('game.trees.price');
    }

    /** The Apples a tree gives in all. */
    public static function apples(): int
    {
        return (int) config('game.trees.apples');
    }

    public static function perDay(): int
    {
        return (int) config('game.trees.per_day');
    }

    /** The Apples a basket holds before the tree stops growing. */
    public static function basketSize(): int
    {
        return self::perDay() * (int) config('game.trees.basket_days');
    }

    public static function saplingDays(): int
    {
        return (int) config('game.trees.sapling_days');
    }

    /** How many trees an animal can have growing at once. */
    public static function most(): int
    {
        return (int) config('game.trees.max');
    }

    /**
     * Plant a tree.
     *
     * @throws GameException
     */
    public function plant(Player $player): AppleTree
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if (AppleTree::query()->where('player_id', $player->id)->whereNull('finished_at')->count() >= self::most()) {
                throw new GameException('You have '.self::most().' trees growing. Harvest one to the end before planting another.');
            }

            Pineapples::spend($player, self::price(), 'Planted an apple tree', 'tree:plant:'.Str::lower((string) Str::ulid()));

            return AppleTree::create([
                'player_id' => $player->id,
                'fruits_at' => CarbonImmutable::now()->addDays(self::saplingDays()),
                'total' => self::apples(),
            ]);
        });
    }

    /**
     * Harvest every tree's basket.
     *
     * @return int The Apples harvested.
     *
     * @throws GameException
     */
    public function harvest(Player $player): int
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $harvested = 0;

            $trees = AppleTree::query()->where('player_id', $player->id)->whereNull('finished_at')->lockForUpdate()->get();

            foreach ($trees as $tree) {
                ['grown' => $grown, 'basket' => $basket, 'until' => $until] = self::growth($tree);

                if ($basket === 0) {
                    continue;
                }

                $tree->forceFill([
                    'grown' => $grown,
                    'basket' => 0,
                    'grown_until' => $until,
                    'finished_at' => $grown >= $tree->total ? CarbonImmutable::now() : null,
                ])->save();

                // Each harvest of a tree is a new count of what it has grown, so it pays once.
                Apples::award($player, $basket, "Harvested {$basket} Apples from your apple tree", "tree:{$tree->id}:{$grown}");
                $harvested += $basket;
            }

            if ($harvested === 0) {
                throw new GameException('No Apples are ready yet.');
            }

            return $harvested;
        });
    }

    /**
     * Where a tree's growing stands now: what it has grown in all, what
     * waits in its basket, and how far its growing has been counted.
     *
     * @return array{grown: int, basket: int, until: CarbonImmutable|null}
     */
    public static function growth(AppleTree $tree, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $fruits = CarbonImmutable::instance($tree->fruits_at);
        $from = $tree->grown_until !== null && $tree->grown_until->greaterThan($fruits) ? CarbonImmutable::instance($tree->grown_until) : $fruits;
        $state = ['grown' => $tree->grown, 'basket' => $tree->basket, 'until' => $tree->grown_until ? CarbonImmutable::instance($tree->grown_until) : null];

        if ($now->lessThanOrEqualTo($from) || $tree->grown >= $tree->total) {
            return $state;
        }

        $perMinute = self::perDay() / (24 * 60);
        $growable = (int) floor($from->diffInMinutes($now) * $perMinute);
        $add = min($growable, self::basketSize() - $tree->basket, $tree->total - $tree->grown);

        // Time spent with a full basket isn't counted: the tree waits.
        $until = $add === $growable ? $from->addMinutes((int) round($add / $perMinute)) : $now;

        return ['grown' => $tree->grown + $add, 'basket' => $tree->basket + $add, 'until' => $until];
    }

    /**
     * Everything the Apples page shows about an animal's trees.
     *
     * @return array<string, mixed>
     */
    public static function page(Player $player): array
    {
        $trees = AppleTree::query()->where('player_id', $player->id)->whereNull('finished_at')->orderBy('id')->get();

        return [
            'price' => self::price(),
            'apples' => self::apples(),
            'perDay' => self::perDay(),
            'basketSize' => self::basketSize(),
            'saplingDays' => self::saplingDays(),
            'most' => self::most(),
            'trees' => $trees->map(function (AppleTree $tree) {
                ['grown' => $grown, 'basket' => $basket] = self::growth($tree);

                return [
                    'id' => $tree->id,
                    'fruitsAt' => $tree->fruits_at->isFuture() ? $tree->fruits_at->toIso8601String() : null,
                    'grown' => $grown,
                    'basket' => $basket,
                    'total' => $tree->total,
                    'full' => $basket >= self::basketSize(),
                ];
            })->values(),
        ];
    }
}
