<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Models\Crime;
use App\Models\Home;
use App\Models\HomeHelp;
use App\Models\Item;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every animal's own home: built where it stands, raised from a Hut to a
 * Cottage to a Fort and on, level after level, with faction mates lending a
 * hand or a Builder's Mortar. Inside it, an animal is hidden from the map and harder
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
        0 => ['name' => 'Tent', 'corn' => 0, 'materials' => ['wool' => 10, 'wood' => 5], 'minutes' => 5],
        1 => ['name' => 'Hut', 'corn' => 60, 'materials' => ['wood' => 10, 'straw' => 10], 'minutes' => 30],
        2 => ['name' => 'Cottage', 'corn' => 150, 'materials' => ['wood' => 20, 'stone' => 15, 'clay' => 10], 'minutes' => 90],
        3 => ['name' => 'Fort', 'corn' => 300, 'materials' => ['stone' => 40, 'wood' => 25, 'iron' => 4], 'minutes' => 180],
    ];

    /** The looks a home can have on the 3D map besides a village house, by name. */
    public const STYLES = [
        'barn' => 'Barn house',
        'coop' => 'Coop',
        'silo' => 'Silo house',
        'windmill' => 'Windmill',
        'water_tower' => 'Water tower',
        'timber_house' => 'Timber house',
        'town_house' => 'Town house',
        'stone_cottage' => 'Stone cottage',
        'low_cottage' => 'Low cottage',
        'inn' => 'Inn',
        'forge' => 'Forge',
        'stable' => 'Stable',
        'mill_house' => 'Mill house',
        'sawmill' => 'Sawmill',
        'bell_tower' => 'Bell tower',
        'family_house' => 'Family house',
        'veranda_house' => 'Veranda house',
        'two_storey' => 'Two-storey house',
        'garage_house' => 'House with a garage',
        'double_house' => 'Double house',
        'tall_flat' => 'Block of flats',
        'porch_flat' => 'Flats with a porch',
        'corner_shop' => 'Corner shop',
    ];

    /**
     * The skin shop, level by level: each skin dresses the home in its
     * colours (roof, then walls) and guards it, making it harder to burn and
     * harder to arrest anyone hiding inside. The first levels cost Corn; the
     * finest cost Apples.
     */
    public const SKIN_SHOP = [
        1 => ['name' => 'Whitewash', 'colours' => ['#8a6a4a', '#efe9dc'], 'corn' => 80, 'apples' => 0, 'defence' => 0.03],
        2 => ['name' => 'Thatch and Clay', 'colours' => ['#c9a24a', '#c98a5a'], 'corn' => 200, 'apples' => 0, 'defence' => 0.06],
        3 => ['name' => 'Timber Frame', 'colours' => ['#5a3a2a', '#e8d9b5'], 'corn' => 400, 'apples' => 0, 'defence' => 0.09],
        4 => ['name' => 'Fieldstone', 'colours' => ['#4f5560', '#a8a39a'], 'corn' => 700, 'apples' => 0, 'defence' => 0.12],
        5 => ['name' => 'Red Brick', 'colours' => ['#3d3a44', '#a8452f'], 'corn' => 0, 'apples' => 10, 'defence' => 0.16],
        6 => ['name' => 'Slate and Oak', 'colours' => ['#2f3640', '#7a5534'], 'corn' => 0, 'apples' => 25, 'defence' => 0.2],
        7 => ['name' => 'Iron-bound', 'colours' => ['#23262b', '#6b6f78'], 'corn' => 0, 'apples' => 50, 'defence' => 0.25],
        8 => ['name' => 'Gilded Keep', 'colours' => ['#d4a52a', '#f3ead2'], 'corn' => 0, 'apples' => 100, 'defence' => 0.3],
    ];

    /** What putting out a fire costs. */
    public const DOUSE_HORSESHOES = 1;

    /** How close an animal must stand to its home to go in or work on it, in paces. */
    public const DOOR = 40;

    /** How far apart two homes must stand, in paces. */
    public const SPACING = 15;

    /** How much each level cuts a bounty hunter's chance at the door. */
    public const ARREST_GUARD = 0.1;

    /** However high a home is built, a hunter at the door keeps this much of a chance: warrants still mean something. */
    public const ARREST_FLOOR = 0.1;

    /** How much each level adds to the chance a would-be burner is caught. */
    public const BURN_GUARD = 0.12;

    /** Faction mates who can lend a hand on one level. */
    public const MOST_HELPERS = 5;

    public static function of(Player $player): ?Home
    {
        return Home::query()->where('player_id', $player->id)->first();
    }

    /**
     * What a level costs and how long it takes. The first three are set;
     * past the Fort, homes keep growing without end: each level costs half
     * as much Corn again as the one before, more stone, wood and iron, and
     * an hour longer, up to a day.
     *
     * @return array{name: string, corn: int, materials: array<string, int>, minutes: int}
     */
    public static function level(int $level): array
    {
        if (isset(self::LEVELS[$level])) {
            return self::LEVELS[$level];
        }

        $past = $level - 3;

        return [
            'name' => self::name($level),
            'corn' => (int) (round(300 * 1.5 ** $past / 10) * 10),
            'materials' => ['stone' => 40 + 15 * $past, 'wood' => 25 + 10 * $past, 'iron' => 4 + 2 * $past],
            'minutes' => min(24 * 60, 180 + 60 * $past),
        ];
    }

    public static function name(int $level): string
    {
        return match (true) {
            $level <= 3 => self::LEVELS[max(0, $level)]['name'],
            $level === 4 => 'Keep',
            $level === 5 => 'Castle',
            default => 'Castle '.($level - 4),
        };
    }

    /** How much a home this high cuts a hunter's chance at its door: never below the floor. */
    public static function arrestCut(int $level, float $chance): float
    {
        return min($level * self::ARREST_GUARD, max(0.0, $chance - self::ARREST_FLOOR));
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
     * Pitch a Tent or build a Hut where you stand.
     *
     * @throws GameException
     */
    public function build(Player $player, int $level = 1): Home
    {
        if (! in_array($level, [0, 1], true)) {
            throw new GameException('Start with a Tent or a Hut.');
        }

        return DB::transaction(function () use ($player, $level) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $this->ensureStill($player);

            if (self::of($player) !== null) {
                throw new GameException('You already have a home. Raise it to the next level instead.');
            }

            $region = $player->location();

            if ($region->isFarmhouse() || $region->isSafe() || $region->isCity() || $region->isIronfield() || $region->wild) {
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

            $this->pay($player, $level);
            Missions::record($player, 'home');

            return Home::create([
                'player_id' => $player->id,
                'region_id' => $region->id,
                'x' => round($x, 1),
                'y' => round($y, 1),
                'level' => $level,
                'ready_at' => CarbonImmutable::now()->addMinutes(self::LEVELS[$level]['minutes']),
            ]);
        });
    }

    /**
     * Set up camp at a spot on the map: right there if you stand on it,
     * or walk there and the Hut goes up when you arrive.
     *
     * @return bool Whether the Hut went up now (false: on the way).
     *
     * @throws GameException
     */
    public function campAt(Player $player, float $x, float $y, int $level = 1): bool
    {
        $player = $player->fresh();

        if (! in_array($level, [0, 1], true)) {
            throw new GameException('Start with a Tent or a Hut.');
        }

        if (self::of($player) !== null) {
            throw new GameException('You already have a home. Raise it to the next level instead.');
        }

        $key = Geography::regionAt($x, $y) ?? throw new GameException("That's past the valley's edge. Pick a spot on the map.");
        $region = Region::query()->where('valley_id', $player->valley_id)->where('key', $key)->first();

        if ($region !== null && ! $region->wild && ($region->isFarmhouse() || $region->isSafe() || $region->isCity() || $region->isIronfield())) {
            throw new GameException("Homes can't go in {$region->name}. Pick a spot in another region.");
        }

        if (($short = self::short($player, $level)) !== null) {
            throw new GameException($short);
        }

        [$px, $py] = Movement::position($player);

        if ($player->walk_arrives_at === null && hypot($px - $x, $py - $y) < self::DOOR) {
            $this->build($player, $level);

            return true;
        }

        app(Movement::class)->walkTo($player, $x, $y);
        $player->fresh()->forceFill(['camp_level' => $level])->save();

        return false;
    }

    /** Put up the Huts of animals that walked off to set up camp and have arrived. */
    public function buildArrivedCamps(int $valleyId): void
    {
        Player::query()
            ->where('valley_id', $valleyId)
            ->whereNotNull('camp_level')
            ->whereNull('walk_arrives_at')
            ->get()
            ->each(function (Player $player) {
                $level = (int) $player->camp_level;
                $player->forceFill(['camp_level' => null])->save();

                try {
                    $this->build($player, $level);
                    Notices::send($player->id, 'home', '🏕 You set up camp. Your '.self::name($level).' is going up.', route('play', [], false));
                } catch (GameException $e) {
                    Notices::send($player->id, 'home', "🏕 You couldn't set up camp: {$e->getMessage()}", route('play', [], false));
                }
            });
    }

    /**
     * Pick how your home looks on the 3D map: a village house (null) or one
     * of the farm buildings. Free, and changed any time.
     *
     * @throws GameException
     */
    public function style(Player $player, ?string $style): Home
    {
        if ($style !== null && ! isset(self::STYLES[$style])) {
            throw new GameException('Pick one of the looks on offer.');
        }

        $home = self::of($player) ?? throw new GameException("You haven't got a home yet.");
        $home->forceFill(['style' => $style])->save();

        return $home;
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
            $this->pay($player, $next);
            $home->forceFill(['level' => $next, 'ready_at' => CarbonImmutable::now()->addMinutes(self::level($next)['minutes'])])->save();

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

            if ($home->level <= 1 && $home->building()) {
                throw new GameException('Your '.self::name($home->level).' is still going up. You can go in once it stands.');
            }

            $player->forceFill(['inside_home_at' => CarbonImmutable::now()])->save();
        });
    }

    public function leave(Player $player): void
    {
        Player::query()->whereKey($player->id)->update(['inside_home_at' => null]);
    }

    /**
     * Break your home down, standing at its door: half the materials of its
     * top level come back to you, and you can set up camp somewhere else.
     *
     * @return array<string, int> The materials you got back.
     *
     * @throws GameException
     */
    public function demolish(Player $player): array
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $home = $this->mine($player);
            $this->ensureAtDoor($player, $home);

            $back = array_map(fn (int $amount) => intdiv($amount, 2), self::level($home->level)['materials']);
            $materials = $player->materials ?? [];

            foreach ($back as $name => $amount) {
                $materials[$name] = (int) ($materials[$name] ?? 0) + $amount;
            }

            $player->forceFill(['materials' => $materials, 'inside_home_at' => null])->save();
            $home->delete();

            return array_filter($back);
        });
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

    /** How much a home's skin from the shop guards it: 0.1 is ten points harder to burn or to arrest inside. */
    public static function skinDefence(?Home $home): float
    {
        return (float) (self::SKIN_SHOP[$home?->skin_level ?? 0]['defence'] ?? 0.0);
    }

    /** How much a shop skin guards an animal hiding in its home. */
    public static function armour(Player $player): float
    {
        return self::hiding($player) ? self::skinDefence(self::of($player)) : 0.0;
    }

    /**
     * Buy the next skin in the shop: Corn for the first levels, Apples for
     * the finest. It replaces the one before and guards the home harder.
     *
     * @throws GameException
     */
    public function buySkin(Player $player): Home
    {
        return DB::transaction(function () use ($player) {
            $player = Players::lock($player);
            $home = $this->mine($player);
            $next = $home->skin_level + 1;
            $skin = self::SKIN_SHOP[$next] ?? throw new GameException('Your home already wears the finest skin in the shop.');

            if ($skin['apples'] > 0) {
                Apples::spend($player, $skin['apples'], "Home skin: {$skin['name']}", "skin:{$home->id}:{$next}");
            } else {
                if ($player->corn < $skin['corn']) {
                    throw new GameException("The {$skin['name']} skin costs {$skin['corn']} Corn. You have {$player->corn}.");
                }

                $player->decrement('corn', $skin['corn']);
            }

            // A skin from the shop shows instead of a home-made one.
            $home->forceFill(['skin_level' => $next, 'skin_item_id' => null])->save();
            Missions::record($player, 'skin');

            return $home;
        });
    }

    /**
     * Every home in the valley for the map, the same for every animal: read
     * in one plain query and kept for a few seconds, so a busy valley's maps
     * don't each rebuild thousands of homes. Each animal's map adds whether a
     * home is its own and whether it can lend a hand.
     *
     * @return list<array<string, mixed>>
     */
    public static function onMap(Valley $valley, ?Season $season): array
    {
        return Cache::remember(self::mapKey($valley->id), (int) config('game.map_cache_seconds'), function () use ($valley, $season) {
            $now = CarbonImmutable::now();
            $burning = $season === null ? [] : Crime::query()
                ->where('season_id', $season->id)
                ->where('kind', CrimeKind::Burn)
                ->whereNull('resolved_at')
                ->pluck('target_player_id')
                ->flip()
                ->all();
            // Home-made skins are few: read just those homes the full way.
            $skins = Home::query()
                ->with(['skin.design', 'skin.openListing'])
                ->whereNotNull('skin_item_id')
                ->whereHas('owner', fn ($query) => $query->where('valley_id', $valley->id))
                ->get()
                ->mapWithKeys(fn (Home $home) => [$home->id => self::skin($home)])
                ->all();

            return DB::table('homes')
                ->join('players', 'players.id', '=', 'homes.player_id')
                ->leftJoin('factions', 'factions.id', '=', 'players.faction_id')
                ->where('players.valley_id', $valley->id)
                ->get(['factions.name as faction_name', 'homes.id', 'homes.x', 'homes.y', 'homes.level', 'homes.ready_at', 'homes.style', 'homes.skin_level', 'homes.skin_item_id',
                    'players.id as owner_id', 'players.name as owner_name', 'players.species', 'players.faction_id', 'players.home_closed_until', 'players.home_burned_at'])
                ->map(function (object $row) use ($now, $burning, $skins) {
                    $building = $row->ready_at !== null && CarbonImmutable::parse($row->ready_at)->gt($now);
                    $closed = $row->home_closed_until !== null && CarbonImmutable::parse($row->home_closed_until)->gt($now);
                    $bought = self::SKIN_SHOP[(int) $row->skin_level] ?? null;

                    return [
                        'id' => (int) $row->id,
                        'owner' => ['id' => (int) $row->owner_id, 'name' => $row->owner_name, 'species' => $row->species],
                        'x' => (float) $row->x,
                        'y' => (float) $row->y,
                        'level' => (int) $row->level,
                        'name' => self::name((int) $row->level),
                        'readyAt' => $building ? CarbonImmutable::parse($row->ready_at)->toIso8601String() : null,
                        'skin' => $skins[$row->id] ?? ($bought === null ? null : ['name' => $bought['name'], 'colours' => $bought['colours'], 'pattern' => 'plain']),
                        'style' => $row->style,
                        // Alight while a burner's fire takes hold, then ash until it's rebuilt.
                        'fire' => isset($burning[$row->owner_id]) ? 'burning' : ($closed && $row->home_burned_at !== null ? 'ash' : null),
                        'factionId' => $row->faction_id === null ? null : (int) $row->faction_id,
                        'factionName' => $row->faction_name,
                    ];
                })
                ->all();
        });
    }

    /** Where the valley's homes for the map are kept; anything that changes the map clears it. */
    public static function mapKey(int $valleyId): string
    {
        return "map:homes:{$valleyId}";
    }

    /** The skin a home shows: one still in its owner's bag and off the shelf, else the shop skin it wears. */
    public static function skin(Home $home): ?array
    {
        $item = $home->skin;

        if ($item === null || $item->player_id !== $home->player_id || $item->used_at !== null || $item->openListing()->exists()) {
            $bought = self::SKIN_SHOP[$home->skin_level] ?? null;

            return $bought === null ? null : ['name' => $bought['name'], 'colours' => $bought['colours'], 'pattern' => 'plain'];
        }

        return ['name' => $item->design->name, 'colours' => $item->design->colours, 'pattern' => $item->design->pattern];
    }

    /**
     * Put out a fire at a home before it burns down: anyone but the burner,
     * standing at its door, with a bucket and a horseshoe. The home stands,
     * and the burner, seen at it, is likelier to be caught.
     *
     * @throws GameException
     */
    public function douse(Player $helper, Home $home): Crime
    {
        return DB::transaction(function () use ($helper, $home) {
            $helper = Players::lock($helper);
            Players::ensureFree($helper);
            $home = Home::query()->with('owner')->findOrFail($home->id);
            $owner = $home->owner;
            $crime = Crime::query()
                ->lockForUpdate()
                ->where('kind', CrimeKind::Burn)
                ->where('target_player_id', $owner->id)
                ->whereNull('resolved_at')
                ->latest('id')
                ->first() ?? throw new GameException("Nothing is burning at {$owner->name}'s home.");

            if ($crime->player_id === $helper->id) {
                throw new GameException('You set this fire. Let it burn, or be seen putting it out.');
            }

            if ($crime->doused_by !== null) {
                throw new GameException('The fire is already out.');
            }

            $this->ensureAtDoor($helper, $home, $home->player_id === $helper->id ? 'your home' : "{$owner->name}'s home");
            Horseshoes::spend($helper, self::DOUSE_HORSESHOES);
            $crime->forceFill(['doused_by' => $helper->id])->save();
            Missions::record($helper, 'douse');
            Rosettes::award($helper, 5, "Put out the fire at {$owner->name}'s home", "douse:{$crime->id}", $helper->valley->currentSeason());

            if ($owner->id !== $helper->id) {
                Notices::send($owner->id, 'crime', "🪣 {$helper->name} put out a fire at your home before it burned down.", route('play', [], false));
            }

            return $crime;
        });
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

    private function ensureAtDoor(Player $player, Home $home, string $whose = 'your home'): void
    {
        [$x, $y] = Movement::position($player);

        if (hypot($home->x - $x, $home->y - $y) > self::DOOR) {
            throw new GameException("Walk to {$whose} first.");
        }
    }

    /**
     * Lend a hand on a faction mate's home while a level goes up: it cuts the
     * time left by a third, once a level each, for a horseshoe.
     *
     * @throws GameException
     */
    public function help(Player $helper, Home $home): Home
    {
        return DB::transaction(function () use ($helper, $home) {
            $helper = Players::lock($helper);
            Players::ensureFree($helper);
            $home = Home::query()->with('owner')->lockForUpdate()->findOrFail($home->id);
            $owner = $home->owner;

            if ($home->player_id === $helper->id) {
                throw new GameException('Ask your faction mates to help build your home.');
            }

            if ($helper->faction_id === null || $helper->faction_id !== $owner->faction_id) {
                throw new GameException("Only {$owner->name}'s faction mates can help build their home.");
            }

            if (! $home->building()) {
                throw new GameException("{$owner->name}'s ".self::name($home->level).' is already standing.');
            }

            $this->ensureAtDoor($helper, $home, "{$owner->name}'s home");
            $helps = HomeHelp::query()->where('home_id', $home->id)->where('level', $home->level);

            if ((clone $helps)->where('player_id', $helper->id)->exists()) {
                throw new GameException("You're already helping build this level of {$owner->name}'s home.");
            }

            if ((clone $helps)->count() >= self::MOST_HELPERS) {
                throw new GameException(self::MOST_HELPERS." are helping already: there's no room for more hands.");
            }

            Horseshoes::spend($helper, 1);
            HomeHelp::create(['home_id' => $home->id, 'player_id' => $helper->id, 'level' => $home->level]);
            $left = max(0, CarbonImmutable::now()->diffInSeconds($home->ready_at));
            $home->forceFill(['ready_at' => CarbonImmutable::now()->addSeconds((int) ceil($left * 2 / 3))])->save();
            Rosettes::award($helper, 3, "Helped build {$owner->name}'s home", "home:{$home->id}:{$home->level}:{$helper->id}", $helper->valley->currentSeason());
            Notices::send($owner->id, 'faction', "🔨 {$helper->name} lent a hand on your ".self::name($home->level).'.', route('play', [], false));

            return $home;
        });
    }

    /**
     * Builder's Mortar: your home goes up a level at once. You pay the
     * level's materials, not its Corn, and don't wait. Called while using
     * the item, inside its transaction.
     *
     * @throws GameException
     */
    public function mortar(Player $player): string
    {
        $home = $this->mine($player);

        if ($home->building()) {
            throw new GameException('Your '.self::name($home->level).' is still going up. Use the mortar once it stands.');
        }

        $next = $home->level + 1;
        $this->pay($player, $next, corn: false);
        $home->forceFill(['level' => $next, 'ready_at' => null])->save();

        return 'The mortar sets: your home is a '.self::name($next).' now.';
    }

    /** Why a player can't pay for a home of this level, or null when they can. */
    private static function short(Player $player, int $level, bool $corn = true): ?string
    {
        $cost = self::level($level);

        if ($corn && $player->corn < $cost['corn']) {
            return "A {$cost['name']} costs {$cost['corn']} Corn. You have {$player->corn}.";
        }

        foreach ($cost['materials'] as $name => $amount) {
            if ((int) (($player->materials ?? [])[$name] ?? 0) < $amount) {
                return "A {$cost['name']} needs {$amount} {$name}. You have ".(int) (($player->materials ?? [])[$name] ?? 0).'.';
            }
        }

        return null;
    }

    private function pay(Player $player, int $level, bool $corn = true): void
    {
        $cost = self::level($level);
        $cost['corn'] = $corn ? $cost['corn'] : 0;

        if (($short = self::short($player, $level, $corn)) !== null) {
            throw new GameException($short);
        }

        $materials = $player->materials ?? [];

        foreach ($cost['materials'] as $name => $amount) {
            $materials[$name] = (int) $materials[$name] - $amount;
        }

        $player->forceFill(['corn' => $player->corn - $cost['corn'], 'materials' => $materials])->save();
    }
}
