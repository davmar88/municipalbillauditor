<?php

namespace App\Game;

use App\Enums\Species;
use App\Models\Faction;
use App\Models\LootDrop;
use App\Models\Player;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staying alive. Every animal gets hungry and thirsty as the hours pass,
 * faster in winter's cold and summer's heat. When it's peckish it eats the
 * Corn it carries, and when it's dry it drinks the water it carries, then
 * its faction's stored water. With nothing left to eat or drink it loses
 * health, and at none it dies: everything it carried is scattered over the
 * valley for anyone to pick up, and it wakes up in the Paddock.
 *
 * Worked out when read, like horseshoes, and settled for everyone by the
 * tick, so animals that are away go hungry too.
 */
final class Survival
{
    /** The water bags an animal can carry its water in: how much each holds and what it costs in Corn. */
    public const BARRELS = [
        0 => ['name' => 'Water skin', 'holds' => 20, 'corn' => 0],
        1 => ['name' => 'Water bucket', 'holds' => 60, 'corn' => 80],
        2 => ['name' => 'Water barrel', 'holds' => 150, 'corn' => 250],
        3 => ['name' => 'Water butt', 'holds' => 300, 'corn' => 600],
    ];

    /** How long dropped loot lies on the map before the crows have it. */
    public const LOOT_HOURS = 48;

    /** How many piles a dead animal's things are scattered into. */
    private const PILES = 3;

    /**
     * Where an animal's needs stand now, without saving anything.
     *
     * @param  array{food: float, drink: float, health: float, at: CarbonImmutable|null, corn: int, water: int, store: int}  $from
     * @return array{food: float, drink: float, health: float, corn: int, water: int, store: int, diedAt: CarbonImmutable|null}
     */
    public static function project(array $from, ?Season $season, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $config = config('game.survival');

        if (! $config['enabled']) {
            return [...$from, 'diedAt' => null];
        }

        ['food' => $food, 'drink' => $drink, 'health' => $health, 'corn' => $corn, 'water' => $water, 'store' => $store] = $from;
        $at = $from['at'] ?? $now;
        $steps = 0;

        // An hour at a time, so the time of year and the eating happen as they would have.
        while ($at->lt($now) && $steps++ < 24 * 30) {
            $next = $at->addHour()->min($now);
            $hours = $at->diffInSeconds($next) / 3600;
            [$hunger, $thirst] = Weather::needs(Weather::at($season, $at));

            $food -= $config['food_per_hour'] * $hunger * $hours;
            $drink -= $config['drink_per_hour'] * $thirst * ($from['thirst'] ?? 1.0) * $hours;

            // Peckish: eat what it carries.
            if ($food < $config['eat_below'] && $corn > 0) {
                $eat = min($corn, (int) ceil(($config['eat_to'] - $food) / $config['food_per_corn']));
                $corn -= $eat;
                $food += $eat * $config['food_per_corn'];
            }

            // Dry: drink its own water, then the faction's.
            if ($drink < $config['eat_below'] && $water + $store > 0) {
                $need = (int) ceil(($config['eat_to'] - $drink) / $config['drink_per_water']);
                $own = min($water, $need);
                $shared = min($store, $need - $own);
                $water -= $own;
                $store -= $shared;
                $drink += ($own + $shared) * $config['drink_per_water'];
            }

            $food = max(0.0, min(100.0, $food));
            $drink = max(0.0, min(100.0, $drink));

            if ($food <= 0) {
                $health -= $config['starving_per_hour'] * $hours;
            }

            if ($drink <= 0) {
                $health -= $config['thirst_per_hour'] * $hours;
            }

            if ($food >= $config['heal_above'] && $drink >= $config['heal_above']) {
                $health += $config['heal_per_hour'] * $hours;
            }

            $health = min(100.0, $health);

            if ($health <= 0) {
                return ['food' => $food, 'drink' => $drink, 'health' => 0.0, 'corn' => $corn, 'water' => $water, 'store' => $store, 'diedAt' => $next];
            }

            $at = $next;
        }

        return ['food' => $food, 'drink' => $drink, 'health' => $health, 'corn' => $corn, 'water' => $water, 'store' => $store, 'diedAt' => null];
    }

    /**
     * What another animal's health looks like on the map, read from a plain
     * row of the players table.
     */
    public static function healthOf(object $row, ?Season $season, int $store = 0): int
    {
        $materials = is_string($row->materials ?? null) ? json_decode($row->materials, true) : ($row->materials ?? []);

        return (int) round(self::project([
            'food' => (float) ($row->food ?? 80),
            'drink' => (float) ($row->drink ?? 80),
            'health' => (float) ($row->health ?? 100),
            'at' => isset($row->vitals_at) ? CarbonImmutable::parse($row->vitals_at) : null,
            'corn' => (int) ($row->corn ?? 0),
            'water' => (int) ($materials['water'] ?? 0),
            'store' => $store,
            'thirst' => match ($row->species ?? null) {
                Species::Llama->value => 0.7,
                Species::CatBlue->value => 0.8,
                default => 1.0,
            },
        ], $season)['health']);
    }

    /**
     * Bring an animal's needs up to now and save them: what it ate and
     * drank, and, if it starved, its death.
     */
    public static function refresh(Player $player, bool $force = false): Player
    {
        if (! config('game.survival.enabled') || (! $force && $player->vitals_at !== null && $player->vitals_at->gt(now()->subMinutes(2)))) {
            return $player;
        }

        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $faction = $player->faction_id !== null ? Faction::query()->lockForUpdate()->find($player->faction_id) : null;
            $season = $player->valley->currentSeason();
            $now = CarbonImmutable::now();
            $store = $faction?->material('water') ?? 0;

            $after = self::project([
                'food' => (float) $player->food,
                'drink' => (float) $player->drink,
                'health' => (float) $player->health,
                'at' => $player->vitals_at,
                'corn' => (int) $player->corn,
                'water' => $player->material('water'),
                'store' => $store,
                // Llamas and blue cats get thirsty more slowly.
                'thirst' => match ($player->species) {
                    Species::Llama => 0.7,
                    Species::CatBlue => 0.8,
                    default => 1.0,
                },
            ], $season, $now);

            $materials = $player->materials ?? [];
            $materials['water'] = $after['water'];
            $player->forceFill([
                'food' => round($after['food'], 2),
                'drink' => round($after['drink'], 2),
                'health' => round($after['health'], 2),
                'vitals_at' => $after['diedAt'] ?? $now,
                'corn' => $after['corn'],
                'materials' => $materials,
            ])->save();

            if ($faction !== null && $after['store'] !== $store) {
                $kept = $faction->materials ?? [];
                $kept['water'] = $after['store'];
                $faction->forceFill(['materials' => $kept])->save();
            }

            if ($after['diedAt'] !== null) {
                self::die($player, $after['food'] <= 0 ? 'hunger' : 'thirst');
            }

            return $player;
        });
    }

    /** Settle everyone in a valley not seen to lately, so animals that are away eat, drink and starve too. */
    public static function settleValley(int $valleyId): int
    {
        if (! config('game.survival.enabled')) {
            return 0;
        }

        // The longest-waiting few hundred each minute, so a big valley never stalls the tick.
        $players = Player::query()
            ->where('valley_id', $valleyId)
            ->where(fn ($query) => $query->whereNull('vitals_at')->orWhere('vitals_at', '<', now()->subMinutes(30)))
            ->orderBy('vitals_at')
            ->limit(400)
            ->get();

        foreach ($players as $player) {
            self::refresh($player, force: true);
        }

        return $players->count();
    }

    /**
     * Hurt an animal: in a robbery or a fight. It can die of it.
     */
    public static function hurt(Player $player, float $amount, string $how): void
    {
        $player = self::refresh($player, force: true);

        if ($player->died_at !== null && $player->died_at->gt(now()->subMinute())) {
            return;
        }

        $health = max(0.0, (float) $player->health - $amount);
        Player::query()->whereKey($player->id)->update(['health' => $health]);

        if ($health <= 0) {
            DB::transaction(fn () => self::die(Players::lock($player), $how));
        }
    }

    /**
     * Eat from what you carry until you're full. Free: you'd eat it anyway.
     *
     * @throws GameException
     */
    public function eat(Player $player): int
    {
        $player = self::refresh($player, force: true);

        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $per = (float) config('game.survival.food_per_corn');
            $eat = min($player->corn, (int) ceil((100 - (float) $player->food) / $per));

            if ($eat < 1) {
                throw new GameException((float) $player->food >= 99 ? "You're full." : 'You have no Corn to eat. Gather some, or ask a friend to send you some.');
            }

            $player->forceFill(['corn' => $player->corn - $eat, 'food' => min(100, (float) $player->food + $eat * $per)])->save();
            Missions::record($player, 'eat');

            return $eat;
        });
    }

    /**
     * Drink your fill. At a trough, a pond or a spring it's free; elsewhere
     * from the water you carry, then your faction's store.
     *
     * @return array{from: string, used: int}
     *
     * @throws GameException
     */
    public function drink(Player $player): array
    {
        $player = self::refresh($player, force: true);

        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $per = (float) config('game.survival.drink_per_water');
            $need = (int) ceil((100 - (float) $player->drink) / $per);

            if ($need < 1) {
                throw new GameException("You're not thirsty.");
            }

            $source = self::waterHere($player);

            if ($source !== null) {
                $player->forceFill(['drink' => 100])->save();
                Missions::record($player, 'drink');

                return ['from' => $source, 'used' => 0];
            }

            $own = min($player->material('water'), $need);
            $faction = $player->faction_id !== null ? Faction::query()->lockForUpdate()->find($player->faction_id) : null;
            $shared = min($faction?->material('water') ?? 0, $need - $own);

            if ($own + $shared < 1) {
                $dry = Weather::dry($player->valley->currentSeason()) ? ' In fall the Paddock trough is dry: try a spring or a mere on the frontier.' : ' Drink at the Paddock trough, or at a pond or spring.';

                throw new GameException('You have no water with you, and your faction has none stored.'.$dry);
            }

            $materials = $player->materials ?? [];
            $materials['water'] = $player->material('water') - $own;
            $player->forceFill(['materials' => $materials, 'drink' => min(100, (float) $player->drink + ($own + $shared) * $per)])->save();

            if ($shared > 0) {
                $kept = $faction->materials ?? [];
                $kept['water'] = $faction->material('water') - $shared;
                $faction->forceFill(['materials' => $kept])->save();
            }

            Missions::record($player, 'drink');

            return ['from' => $shared > 0 ? 'store' : 'bag', 'used' => $own + $shared];
        });
    }

    /** Where an animal can drink freely as it stands: a spring or a mere, or the Paddock trough outside fall. */
    public static function waterHere(Player $player): ?string
    {
        if ($player->walk_arrives_at !== null) {
            return null;
        }

        $region = $player->location();

        // The Duck Pond is shallow: it dries up in fall, like the trough. Springs and meres don't.
        if ($region->produces === 'water' && ! ($region->key === 'duck_pond' && Weather::dry($player->valley->currentSeason()))) {
            return $region->name;
        }

        if ($region->key === 'paddock' && ! Weather::dry($player->valley->currentSeason())) {
            return 'the Paddock trough';
        }

        return null;
    }

    /** How much water an animal can carry. */
    public static function waterCap(Player $player): int
    {
        $holds = self::BARRELS[(int) $player->water_barrel]['holds'] ?? self::BARRELS[0]['holds'];

        // Llamas carry twice as much, blue cats half as much again.
        return match ($player->species) {
            Species::Llama => $holds * 2,
            Species::CatBlue => (int) round($holds * 1.5),
            default => $holds,
        };
    }

    /**
     * Buy a bigger water bag, so you can carry more water through the fall.
     *
     * @throws GameException
     */
    public function buyBarrel(Player $player): string
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $next = self::BARRELS[(int) $player->water_barrel + 1] ?? throw new GameException('You already have the biggest water butt there is.');

            if ($player->corn < $next['corn']) {
                throw new GameException("A {$next['name']} costs {$next['corn']} Corn. You have {$player->corn}.");
            }

            $player->forceFill(['corn' => $player->corn - $next['corn'], 'water_barrel' => (int) $player->water_barrel + 1])->save();
            Missions::record($player, 'barrel');

            return $next['name'];
        });
    }

    /**
     * An animal dies: what it carried is scattered over the valley in a few
     * piles, and it wakes up in the Paddock, hungry and thirsty but whole.
     * Apples, Pineapples and items are never lost.
     */
    public static function die(Player $player, string $how): void
    {
        $materials = array_filter($player->materials ?? [], fn ($amount) => (int) $amount > 0);
        $corn = (int) $player->corn;
        $piles = self::PILES;

        if ($corn > 0 || $materials !== []) {
            for ($i = 0; $i < $piles; $i++) {
                $share = fn (int $amount) => intdiv($amount, $piles) + ($i < $amount % $piles ? 1 : 0);
                $pile = array_filter(array_map($share, $materials), fn ($amount) => $amount > 0);
                $pileCorn = $share($corn);

                if ($pileCorn === 0 && $pile === []) {
                    continue;
                }

                [$x, $y] = self::randomSpot($player->id * 31 + $i);
                LootDrop::create([
                    'valley_id' => $player->valley_id,
                    'player_id' => $player->id,
                    'x' => $x,
                    'y' => $y,
                    'corn' => $pileCorn,
                    'materials' => $pile,
                    'expires_at' => now()->addHours(self::LOOT_HOURS),
                ]);
            }
        }

        $start = config('game.survival.start');
        $player->forceFill([
            'corn' => 0,
            'materials' => array_fill_keys(array_keys($player->materials ?? []), 0),
            'food' => $start['food'],
            'drink' => $start['drink'],
            'health' => 100,
            'vitals_at' => now(),
            'died_at' => now(),
            'deaths' => (int) $player->deaths + 1,
            'region_id' => $player->valley->region('paddock')->id,
            'pos_x' => Geography::hub('paddock')[0],
            'pos_y' => Geography::hub('paddock')[1],
            'walk_from_x' => null,
            'walk_from_y' => null,
            'walk_to_x' => null,
            'walk_to_y' => null,
            'walk_started_at' => null,
            'walk_arrives_at' => null,
            'camp_level' => null,
            'inside_home_at' => null,
        ])->save();

        $cause = match ($how) {
            'hunger' => 'starved',
            'thirst' => 'died of thirst',
            default => 'died of its wounds',
        };
        $lost = $corn > 0 || $materials !== [] ? ' Everything you carried lies scattered over the valley for anyone to pick up.' : '';
        Notices::send($player->id, 'death', "💀 You {$cause}.{$lost} You woke up in the Paddock: eat and drink before you set out again.", route('play', [], false));

        if ($player->faction_id !== null) {
            Notices::send(Notices::members($player->faction_id)->reject(fn ($id) => $id === $player->id), 'death', "💀 {$player->name} {$cause}.", route('play', [], false));
        }

        Live::touch($player->valley_id);
    }

    /** A random spot on open, reachable ground: not off the edge, not inside wild land. */
    private static function randomSpot(int $seed): array
    {
        [$left, $top, $width, $height] = Geography::REACH;
        mt_srand($seed + (int) now()->format('U'));

        for ($try = 0; $try < 40; $try++) {
            $x = $left + mt_rand(0, 1000) / 1000 * $width;
            $y = $top + mt_rand(0, 1000) / 1000 * $height;
            $key = Geography::regionAt($x, $y);

            if ($key !== null && ! str_starts_with($key, 'wild_')) {
                return [round($x, 1), round($y, 1)];
            }
        }

        return Geography::hub('paddock');
    }

    /**
     * Pick up a pile of loot where it lies.
     *
     * @return array{corn: int, materials: array<string, int>}
     *
     * @throws GameException
     */
    public function pickUp(Player $player, LootDrop $drop): array
    {
        return DB::transaction(function () use ($player, $drop) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $drop = LootDrop::query()->lockForUpdate()->findOrFail($drop->id);

            if ($drop->picked_at !== null || $drop->expires_at->isPast() || $drop->valley_id !== $player->valley_id) {
                throw new GameException('Someone got there first.');
            }

            [$x, $y] = Movement::position($player);
            $here = $player->walk_arrives_at === null ? $player->location()->key : null;
            $there = Geography::regionAt($drop->x, $drop->y);
            $close = hypot($drop->x - $x, $drop->y - $y) <= 80 || ($here !== null && $here !== 'scrubland' && $here === $there);

            if (! $close) {
                throw new GameException('Walk over to it first: you pick it up where it lies.');
            }

            Horseshoes::spend($player, 1);
            $materials = $player->materials ?? [];

            foreach ($drop->materials ?? [] as $name => $amount) {
                $materials[$name] = (int) ($materials[$name] ?? 0) + (int) $amount;
            }

            $player->forceFill(['corn' => $player->corn + $drop->corn, 'materials' => $materials])->save();
            $drop->forceFill(['picked_by' => $player->id, 'picked_at' => now()])->save();
            Missions::record($player, 'loot');
            Live::touch($player->valley_id);

            return ['corn' => (int) $drop->corn, 'materials' => $drop->materials ?? []];
        });
    }
}
