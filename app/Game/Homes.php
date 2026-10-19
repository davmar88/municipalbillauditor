<?php

namespace App\Game;

use App\Enums\ItemTemplate;
use App\Models\Home;
use App\Models\Item;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every animal's own home: built where it stands, raised from a Hut to a
 * Cottage to a Fort. Inside it, an animal is hidden from the map and harder
 * to arrest; the higher it is built, the harder it is to burn down. A home
 * skin from the market changes how it looks.
 */
final class Homes
{
    /**
     * Each level: its name, what it costs and how long it takes to build.
     *
     * @var array<int, array{name: string, corn: int, materials: array<string, int>, minutes: int}>
     */
    public const LEVELS = [
        1 => ['name' => 'Hut', 'corn' => 60, 'materials' => ['wood' => 10, 'straw' => 10], 'minutes' => 30],
        2 => ['name' => 'Cottage', 'corn' => 150, 'materials' => ['wood' => 20, 'stone' => 15, 'clay' => 10], 'minutes' => 90],
        3 => ['name' => 'Fort', 'corn' => 300, 'materials' => ['stone' => 40, 'wood' => 25, 'iron' => 4], 'minutes' => 180],
    ];

    /** How close an animal must stand to its home to go in or work on it, in paces. */
    public const DOOR = 40;

    /** How far apart two homes must stand, in paces. */
    public const SPACING = 30;

    /** How much each level cuts a bounty hunter's chance at the door. */
    public const ARREST_GUARD = 0.1;

    /** How much each level adds to the chance a would-be burner is caught. */
    public const BURN_GUARD = 0.12;

    public static function of(Player $player): ?Home
    {
        return Home::query()->where('player_id', $player->id)->first();
    }

    public static function name(int $level): string
    {
        return self::LEVELS[$level]['name'] ?? 'Home';
    }

    /** Whether the animal is hiding inside a home that stands. */
    public static function hiding(Player $player): bool
    {
        return $player->inside_home_at !== null;
    }

    /** The level that guards an animal: its home's, if it's inside. */
    public static function guard(Player $player): int
    {
        return self::hiding($player) ? (self::of($player)?->level ?? 0) : 0;
    }

    /**
     * Build a Hut where you stand.
     *
     * @throws GameException
     */
    public function build(Player $player): Home
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $this->ensureStill($player);

            if (self::of($player) !== null) {
                throw new GameException('You already have a home. Raise it to the next level instead.');
            }

            $region = $player->location();

            if ($region->isFarmhouse() || $region->isSafe() || $region->isCity() || $region->wild) {
                throw new GameException("Homes can't go in {$region->name}. Pick a spot in another region.");
            }

            [$x, $y] = Movement::position($player);
            $crowded = Home::query()
                ->whereHas('owner', fn ($query) => $query->where('valley_id', $player->valley_id))
                ->get(['x', 'y'])
                ->contains(fn (Home $home) => hypot($home->x - $x, $home->y - $y) < self::SPACING);

            if ($crowded) {
                throw new GameException('Another home stands right here. Walk a little further off.');
            }

            $this->pay($player, 1);

            return Home::create([
                'player_id' => $player->id,
                'region_id' => $region->id,
                'x' => round($x, 1),
                'y' => round($y, 1),
                'level' => 1,
                'ready_at' => CarbonImmutable::now()->addMinutes(self::LEVELS[1]['minutes']),
            ]);
        });
    }

    /**
     * Raise your home a level: Hut to Cottage, Cottage to Fort.
     *
     * @throws GameException
     */
    public function upgrade(Player $player): Home
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $home = $this->mine($player);
            $this->ensureAtDoor($player, $home);

            if ($home->building()) {
                throw new GameException('Your '.self::name($home->level).' is still going up.');
            }

            $next = $home->level + 1;

            if (! isset(self::LEVELS[$next])) {
                throw new GameException('Your home is already a Fort.');
            }

            $this->pay($player, $next);
            $home->forceFill(['level' => $next, 'ready_at' => CarbonImmutable::now()->addMinutes(self::LEVELS[$next]['minutes'])])->save();

            return $home;
        });
    }

    /**
     * Go inside to hide: off the map, and harder to catch.
     *
     * @throws GameException
     */
    public function enter(Player $player): void
    {
        DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $home = $this->mine($player);
            $this->ensureStill($player);
            $this->ensureAtDoor($player, $home);

            if ($home->level === 1 && $home->building()) {
                throw new GameException('Your Hut is still going up. You can go in once it stands.');
            }

            $player->forceFill(['inside_home_at' => CarbonImmutable::now()])->save();
        });
    }

    public function leave(Player $player): void
    {
        Player::query()->whereKey($player->id)->update(['inside_home_at' => null]);
    }

    /**
     * Put a home skin from your bag on your home; it stays yours.
     *
     * @throws GameException
     */
    public function dress(Player $player, ?int $itemId): Home
    {
        return DB::transaction(function () use ($player, $itemId) {
            $player = Players::lock($player);
            $home = $this->mine($player);

            if ($itemId === null) {
                $home->forceFill(['skin_item_id' => null])->save();

                return $home;
            }

            $item = Item::query()->with('design')->find($itemId);

            if ($item === null || $item->player_id !== $player->id || $item->used_at !== null) {
                throw new GameException("That skin isn't in your bag.");
            }

            if ($item->design->template !== ItemTemplate::Skin) {
                throw new GameException('Only a home skin goes on a home.');
            }

            if ($item->openListing()->exists()) {
                throw new GameException('That skin is on your shop shelf. Take it off the shelf first.');
            }

            $home->forceFill(['skin_item_id' => $item->id])->save();

            return $home;
        });
    }

    /**
     * A home that was set on fire drops a level, or a Hut is closed for a
     * day; whoever hid inside is smoked out.
     */
    public static function burned(int $playerId): void
    {
        $home = Home::query()->where('player_id', $playerId)->first();

        if ($home !== null && $home->level > 1) {
            $home->forceFill(['level' => $home->level - 1, 'ready_at' => null])->save();
        }

        Player::query()->whereKey($playerId)->update(['inside_home_at' => null]);
    }

    /** The skin a home shows: one still in its owner's bag and off the shelf. */
    public static function skin(Home $home): ?array
    {
        $item = $home->skin;

        if ($item === null || $item->player_id !== $home->player_id || $item->used_at !== null || $item->openListing()->exists()) {
            return null;
        }

        return ['name' => $item->design->name, 'colours' => $item->design->colours, 'pattern' => $item->design->pattern];
    }

    private function mine(Player $player): Home
    {
        return Home::query()->lockForUpdate()->where('player_id', $player->id)->first()
            ?? throw new GameException("You don't have a home yet. Build a Hut where you stand.");
    }

    private function ensureStill(Player $player): void
    {
        if ($player->walk_arrives_at !== null) {
            throw new GameException('Stop walking first.');
        }
    }

    private function ensureAtDoor(Player $player, Home $home): void
    {
        [$x, $y] = Movement::position($player);

        if (hypot($home->x - $x, $home->y - $y) > self::DOOR) {
            throw new GameException('Walk to your home first.');
        }
    }

    private function pay(Player $player, int $level): void
    {
        $cost = self::LEVELS[$level];

        if ($player->corn < $cost['corn']) {
            throw new GameException("A {$cost['name']} costs {$cost['corn']} Corn. You have {$player->corn}.");
        }

        $materials = $player->materials ?? [];

        foreach ($cost['materials'] as $name => $amount) {
            if ((int) ($materials[$name] ?? 0) < $amount) {
                throw new GameException("A {$cost['name']} needs {$amount} {$name}. You have ".(int) ($materials[$name] ?? 0).'.');
            }
        }

        foreach ($cost['materials'] as $name => $amount) {
            $materials[$name] = (int) $materials[$name] - $amount;
        }

        $player->forceFill(['corn' => $player->corn - $cost['corn'], 'materials' => $materials])->save();
    }
}
