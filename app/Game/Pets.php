<?php

namespace App\Game;

use App\Models\Pet;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pets (her ask, 2026-10-22): one per animal, something to look after every
 * day. A dog sniffs out more when gathering; a horse carries its rider
 * faster. Each feed (once every 12 hours) brings it on, a level every 3
 * feeds up to level 5. Left unfed for 2 days it sulks and helps with
 * nothing until it's fed again.
 */
final class Pets
{
    public const MAX_LEVEL = 5;

    public const FEEDS_PER_LEVEL = 3;

    public const FEED_CORN = 15;

    public const FEED_EVERY_HOURS = 12;

    public const SULKS_AFTER_HOURS = 48;

    /** @var array<string, array{label: string, emoji: string, corn: int, does: string, step: float}> */
    public const KINDS = [
        'dog' => ['label' => 'Dog', 'emoji' => '🐕', 'corn' => 300, 'does' => 'gathers', 'step' => 0.05],
        'horse' => ['label' => 'Horse', 'emoji' => '🐎', 'corn' => 500, 'does' => 'walks', 'step' => 0.08],
    ];

    /**
     * Take in a pet.
     *
     * @throws GameException
     */
    public function adopt(Player $player, string $kind, string $name): Pet
    {
        $info = self::KINDS[$kind] ?? throw new GameException('Pick a dog or a horse.');
        $name = trim($name);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 20) {
            throw new GameException('Give your pet a name of 2 to 20 letters.');
        }

        return DB::transaction(function () use ($player, $kind, $name, $info) {
            $player = Players::lock($player);

            if (Pet::query()->where('player_id', $player->id)->exists()) {
                throw new GameException('You already have a pet. One is plenty to look after.');
            }

            if ($player->corn < $info['corn']) {
                throw new GameException("A {$info['label']} costs {$info['corn']} Corn and you have {$player->corn}.");
            }

            $player->forceFill(['corn' => $player->corn - $info['corn']])->save();

            return Pet::create(['player_id' => $player->id, 'kind' => $kind, 'name' => $name, 'level' => 1, 'feeds' => 0, 'fed_at' => CarbonImmutable::now()]);
        });
    }

    /**
     * Feed your pet: once every 12 hours, and every third feed it grows a level.
     *
     * @throws GameException
     */
    public function feed(Player $player): Pet
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $pet = Pet::query()->where('player_id', $player->id)->lockForUpdate()->first() ?? throw new GameException('You have no pet yet.');

            if ($pet->fed_at !== null && $pet->fed_at->gt(CarbonImmutable::now()->subHours(self::FEED_EVERY_HOURS))) {
                throw new GameException("{$pet->name} is full. Feed again at ".Clock::local($pet->fed_at->addHours(self::FEED_EVERY_HOURS))->format('H:i').'.');
            }

            if ($player->corn < self::FEED_CORN) {
                throw new GameException('A feed costs '.self::FEED_CORN." Corn and you have {$player->corn}.");
            }

            $player->forceFill(['corn' => $player->corn - self::FEED_CORN])->save();
            $feeds = $pet->feeds + 1;
            $pet->forceFill([
                'feeds' => $feeds,
                'level' => min(self::MAX_LEVEL, 1 + intdiv($feeds, self::FEEDS_PER_LEVEL)),
                'fed_at' => CarbonImmutable::now(),
            ])->save();

            return $pet;
        });
    }

    /** A pet that's been fed lately helps; a sulking one doesn't. */
    public static function happy(?Pet $pet): bool
    {
        return $pet !== null && $pet->fed_at !== null && $pet->fed_at->gt(CarbonImmutable::now()->subHours(self::SULKS_AFTER_HOURS));
    }

    public static function bonus(?Pet $pet, string $kind): float
    {
        return $pet !== null && $pet->kind === $kind && self::happy($pet) ? 1 + self::KINDS[$kind]['step'] * $pet->level : 1.0;
    }

    /** How much faster a horse carries its rider. */
    public static function speed(Player $player): float
    {
        return self::bonus(self::of($player), 'horse');
    }

    /** How much more a dog sniffs out when gathering. */
    public static function gathering(Player $player): float
    {
        return self::bonus(self::of($player), 'dog');
    }

    public static function of(Player $player): ?Pet
    {
        return Pet::query()->where('player_id', $player->id)->first();
    }

    /**
     * Your pet, or what you could adopt, for the Valley page.
     *
     * @return array<string, mixed>
     */
    public static function card(Player $player): array
    {
        $pet = self::of($player);

        return [
            'pet' => $pet === null ? null : [
                'kind' => $pet->kind,
                'label' => self::KINDS[$pet->kind]['label'],
                'emoji' => self::KINDS[$pet->kind]['emoji'],
                'name' => $pet->name,
                'level' => $pet->level,
                'max' => self::MAX_LEVEL,
                'feedsToNext' => $pet->level >= self::MAX_LEVEL ? null : self::FEEDS_PER_LEVEL - ($pet->feeds % self::FEEDS_PER_LEVEL),
                'bonus' => (int) round((self::bonus($pet, $pet->kind) - 1) * 100),
                'happy' => self::happy($pet),
                'does' => self::KINDS[$pet->kind]['does'],
                'canFeedAt' => $pet->fed_at?->addHours(self::FEED_EVERY_HOURS)->toIso8601String(),
            ],
            'kinds' => array_map(fn (string $kind, array $info) => [
                'kind' => $kind,
                'label' => $info['label'],
                'emoji' => $info['emoji'],
                'corn' => $info['corn'],
                'per' => (int) round($info['step'] * 100),
                'does' => $info['does'],
            ], array_keys(self::KINDS), self::KINDS),
            'feedCorn' => self::FEED_CORN,
        ];
    }
}
