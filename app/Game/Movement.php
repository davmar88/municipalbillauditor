<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Models\Attack;
use App\Models\Crime;
use App\Models\Player;
use App\Models\Region;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Walking the map. An animal stands at a point and walks in a straight line
 * to any point it picks, at its walking pace: a Speedo makes it faster.
 * Wherever it is, it stands in the region under its feet, so it can gather
 * or act there as it passes. Walking is free: it only takes time.
 */
final class Movement
{
    /** How far an animal walks in a minute, in paces: the map's units. */
    public static function pace(Player $player): float
    {
        return (float) config('game.walk.paces_per_minute') * Powers::speed($player) * $player->species->pace() * Pets::speed($player);
    }

    /**
     * Where an animal is now.
     *
     * @return array{0: float, 1: float}
     */
    public static function position(Player $player, ?CarbonImmutable $now = null): array
    {
        if ($player->walk_arrives_at !== null) {
            $now ??= CarbonImmutable::now();
            $length = max(1, $player->walk_started_at->diffInSeconds($player->walk_arrives_at));
            $done = min(1, max(0, $player->walk_started_at->diffInSeconds($now) / $length));

            return [
                $player->walk_from_x + ($player->walk_to_x - $player->walk_from_x) * $done,
                $player->walk_from_y + ($player->walk_to_y - $player->walk_from_y) * $done,
            ];
        }

        $key = $player->location()->key;

        // A point still in the animal's region; otherwise it was moved there some other way.
        // Out in the scrub, which lies everywhere between the regions, an animal is wherever it stopped.
        if ($player->pos_x !== null && $player->pos_y !== null && (Geography::regionAt($player->pos_x, $player->pos_y) === $key || ($key === 'scrubland' && Geography::reachable($player->pos_x, $player->pos_y)))) {
            return [$player->pos_x, $player->pos_y];
        }

        return Geography::hub($key);
    }

    /**
     * Where an animal is drawn on the map: where it walks or stopped, or a spot
     * of its own round its region's hub, so a robber heads to where it is seen.
     *
     * @return array{0: float, 1: float}
     */
    public static function spot(Player $player): array
    {
        if ($player->walk_arrives_at !== null) {
            return self::position($player);
        }

        return self::standing($player->id, $player->location()->key, $player->pos_x, $player->pos_y);
    }

    /** @return array{0: float, 1: float} */
    public static function standing(int $id, string $key, ?float $x, ?float $y): array
    {
        if ($x !== null && $y !== null && (Geography::regionAt($x, $y) === $key || ($key === 'scrubland' && Geography::reachable($x, $y)))) {
            return [$x, $y];
        }

        [$hx, $hy] = Geography::hub($key);
        $angle = $id * 2.39996;
        $reach = 22 + ($id % 4) * 9;

        return [round($hx + cos($angle) * $reach, 1), round($hy + sin($angle) * $reach, 1)];
    }

    /**
     * Set off on a robbery or a burning: walk to the spot beside the target.
     * The player must be locked. Returns when it gets there.
     *
     * @param  array{0: float, 1: float}  $to
     */
    public function strikeOut(Player $player, array $to): CarbonImmutable
    {
        $this->head($player, self::position($player), $to);

        return $player->walk_arrives_at ?? CarbonImmutable::now();
    }

    /**
     * Start walking to a point, from wherever the animal is now.
     *
     * @throws GameException
     */
    public function walkTo(Player $player, float $x, float $y): Player
    {
        return DB::transaction(function () use ($player, $x, $y) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $target = self::regionUnder($player, $x, $y)
                ?? throw new GameException("That's past the valley's edge. Pick a spot on the map.");

            if ($target->wild) {
                throw new GameException("{$target->name} is still wild land. Its farm's holders can grow into it.");
            }

            // Heading somewhere else while leading a raid turns the raid back.
            Attack::query()
                ->where('player_id', $player->id)
                ->whereNull('resolved_at')
                ->where('lands_at', '>', CarbonImmutable::now())
                ->where('region_id', '!=', $target->id)
                ->get()
                ->each(fn (Attack $attack) => app(Battles::class)->callOff($player, $attack, stop: false));

            $this->head($player, self::position($player), [$x, $y]);
            Missions::record($player, 'walk');

            return $player;
        });
    }

    /**
     * Walk to where a region's lanes meet.
     *
     * @throws GameException
     */
    public function walk(Player $player, Region $target): Player
    {
        if ($target->valley_id !== $player->valley_id) {
            throw new GameException('That region is in another valley.');
        }

        [$x, $y] = Geography::hub($target->key);

        return $this->walkTo($player, $x, $y);
    }

    /** Stop where the animal is. */
    public function stop(Player $player): Player
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);

            if ($player->walk_arrives_at !== null) {
                [$x, $y] = self::position($player);
                $player->forceFill(self::still($x, $y))->save();
            }

            return $player;
        });
    }

    /**
     * Carry on to the same place at the animal's pace now, say after a
     * Speedo. The player must be locked.
     */
    public function rethink(Player $player): void
    {
        if ($player->walk_arrives_at !== null && $player->walk_arrives_at->isFuture()) {
            $from = self::position($player);
            $this->head($player, $from, [$player->walk_to_x, $player->walk_to_y]);

            // On the way to rob or burn: the red line speeds up with the animal, and lands when it arrives.
            $strike = Crime::query()
                ->where('player_id', $player->id)
                ->whereNull('resolved_at')
                ->whereNotNull('lands_at')
                ->where('lands_at', '>', CarbonImmutable::now())
                ->latest('id')
                ->first();

            if ($strike !== null && $player->walk_arrives_at !== null) {
                $arrives = CarbonImmutable::instance($player->walk_arrives_at);
                $strike->forceFill([
                    'from_x' => round($from[0], 1),
                    'from_y' => round($from[1], 1),
                    'path_started_at' => CarbonImmutable::now(),
                    'lands_at' => $arrives,
                    // A robbery happens as the robber arrives; a fire keeps its burning time.
                    'settles_at' => $strike->kind === CrimeKind::Rob ? $arrives : $strike->settles_at,
                ])->save();
            }
        }
    }

    /**
     * Bring an animal's walk up to now: on arrival it stands at the end,
     * and on the way it stands in whichever region it is passing through.
     */
    public static function settle(Player $player): void
    {
        if ($player->walk_arrives_at === null) {
            return;
        }

        [$x, $y] = self::position($player);
        $changes = $player->walk_arrives_at->isFuture() ? [] : self::still($x, $y);
        $region = self::regionUnder($player, $x, $y);

        if ($region !== null && ! $region->wild && $region->id !== $player->region_id) {
            $changes['region_id'] = $region->id;
        }

        if ($changes !== []) {
            $player->forceFill($changes)->save();
            $player->unsetRelation('region');
        }
    }

    /** Settle every animal in the valley that has arrived since it was last seen. */
    public static function settleArrived(int $valleyId): void
    {
        Player::query()
            ->where('valley_id', $valleyId)
            ->whereNotNull('walk_arrives_at')
            ->where('walk_arrives_at', '<=', CarbonImmutable::now())
            ->get()
            ->each(fn (Player $player) => self::settle($player));
    }

    /**
     * Where an animal is and where it is going, for the map.
     *
     * @return array<string, mixed>
     */
    public static function map(Player $player): array
    {
        [$x, $y] = self::position($player);
        $walking = $player->walk_arrives_at !== null && $player->walk_arrives_at->isFuture();

        return [
            'x' => round($x, 1),
            'y' => round($y, 1),
            'pace' => (int) round(self::pace($player)),
            'walk' => $walking ? [
                'from' => [round($player->walk_from_x, 1), round($player->walk_from_y, 1)],
                'to' => [round($player->walk_to_x, 1), round($player->walk_to_y, 1)],
                'startedAt' => $player->walk_started_at->toIso8601String(),
                'arrivesAt' => $player->walk_arrives_at->toIso8601String(),
                'region' => self::regionUnder($player, $player->walk_to_x, $player->walk_to_y)?->name,
            ] : null,
        ];
    }

    /**
     * @throws GameException
     */
    public static function ensureStandingIn(Player $player, Region $region, string $doing): void
    {
        if ($player->location()->id !== $region->id) {
            throw new GameException("Walk to {$region->name} first: you {$doing} where you stand.");
        }
    }

    /**
     * Set off in a straight line.
     *
     * @param  array{0: float, 1: float}  $from
     * @param  array{0: float, 1: float}  $to
     */
    private function head(Player $player, array $from, array $to): void
    {
        $seconds = (int) ceil(hypot($to[0] - $from[0], $to[1] - $from[1]) / self::pace($player) * 60);
        // Setting off means stepping out of your home, and calls off any camp you were walking to set up.
        $player->inside_home_at = null;
        $player->camp_level = null;

        if ($seconds < 1) {
            $changes = self::still($to[0], $to[1]);
            $region = self::regionUnder($player, $to[0], $to[1]);

            if ($region !== null && ! $region->wild) {
                $changes['region_id'] = $region->id;
            }

            $player->forceFill($changes)->save();
            $player->unsetRelation('region');

            return;
        }

        $now = CarbonImmutable::now();
        $player->forceFill([
            'pos_x' => $from[0],
            'pos_y' => $from[1],
            'walk_from_x' => $from[0],
            'walk_from_y' => $from[1],
            'walk_to_x' => $to[0],
            'walk_to_y' => $to[1],
            'walk_started_at' => $now,
            'walk_arrives_at' => $now->addSeconds($seconds),
        ])->save();
    }

    /** @return array<string, float|null> */
    private static function still(float $x, float $y): array
    {
        return [
            'pos_x' => $x,
            'pos_y' => $y,
            'walk_from_x' => null,
            'walk_from_y' => null,
            'walk_to_x' => null,
            'walk_to_y' => null,
            'walk_started_at' => null,
            'walk_arrives_at' => null,
        ];
    }

    /** The region under a point. Wild land round a farm is open scrub until the farm grows into it. */
    private static function regionUnder(Player $player, float $x, float $y): ?Region
    {
        $key = Geography::regionAt($x, $y);

        if ($key === null) {
            return null;
        }

        $region = Region::query()->where('valley_id', $player->valley_id)->where('key', $key)->first();

        return $region !== null && $region->wild
            ? Region::query()->where('valley_id', $player->valley_id)->where('key', 'scrubland')->first() ?? $region
            : $region;
    }
}
