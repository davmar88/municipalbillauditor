<?php

namespace App\Game;

use App\Enums\Species;
use App\Models\Player;
use App\Models\PlayerMission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Missions grow a player. The story, "Arrival in Hollowmere", walks a new
 * animal through the valley one step at a time: the next step opens when
 * the last is claimed. Every day each animal also gets three jobs picked
 * for it, from what its species is good at, its level, and whether it runs
 * with a faction or alone. The game records what animals do as events
 * (walk, gather, sale...), and missions count the events they ask for.
 */
final class Missions
{
    public const DAILY_JOBS = 3;

    /**
     * The story, in order.
     *
     * @var array<string, array{chapter: string, title: string, story: string, goal: string, events: list<string>, target: int, reward: array{corn?: int, rosettes?: int, horseshoes?: int, materials?: array<string, int>}, level?: int}>
     */
    private const STORY = [
        'arrival.first_steps' => [
            'chapter' => 'Arrival',
            'title' => 'Through the gate',
            'story' => 'The Paddock gate creaks open. The humans are long gone, and the whole valley is yours to cross.',
            'goal' => 'Walk to a region next to the Paddock.',
            'events' => ['walk'],
            'target' => 1,
            'reward' => ['corn' => 20, 'rosettes' => 5],
        ],
        'arrival.first_harvest' => [
            'chapter' => 'Arrival',
            'title' => 'Living off the land',
            'story' => 'Nobody feeds you now. The fields, woods and yards give to whoever works them.',
            'goal' => 'Gather 3 times where you stand.',
            'events' => ['gather'],
            'target' => 3,
            'reward' => ['corn' => 30, 'rosettes' => 5, 'horseshoes' => 2],
        ],
        'arrival.hello' => [
            'chapter' => 'Arrival',
            'title' => 'A new face',
            'story' => 'News travels fast in the square. Let the valley know you have arrived.',
            'goal' => 'Write a message in chat.',
            'events' => ['chat'],
            'target' => 1,
            'reward' => ['corn' => 15, 'rosettes' => 5],
        ],
        'herd.belong' => [
            'chapter' => 'Finding your herd',
            'title' => 'Pick a side',
            'story' => 'Factions hold the land and the House rules them all. Run with one, start your own, or live outside the law.',
            'goal' => 'Join or found a faction, or commit a crime as a lone wolf.',
            'events' => ['join_faction', 'crime'],
            'target' => 1,
            'reward' => ['corn' => 40, 'rosettes' => 10],
        ],
        'herd.pull_weight' => [
            'chapter' => 'Finding your herd',
            'title' => 'Pull your weight',
            'story' => 'A store full of materials raises Dens and Watchtowers. A lone wolf keeps everything it gathers.',
            'goal' => 'Give 5 to your faction\'s store, or gather 5 more times.',
            'events' => ['give', 'gather'],
            'target' => 5,
            'reward' => ['corn' => 30, 'rosettes' => 10, 'materials' => ['wood' => 5, 'straw' => 5]],
        ],
        'living.design' => [
            'chapter' => 'Making a living',
            'title' => 'An idea of your own',
            'story' => 'Anything sold in Hollowmere was made by an animal like you. Your first design is checked quickly before it can be made.',
            'goal' => 'Design an item in the Workshop.',
            'events' => ['design'],
            'target' => 1,
            'reward' => ['corn' => 30, 'rosettes' => 10],
        ],
        'living.craft' => [
            'chapter' => 'Making a living',
            'title' => 'Made by hoof',
            'story' => 'Every copy is numbered. The lower the number, the more a collector pays.',
            'goal' => 'Make a copy of one of your designs.',
            'events' => ['craft'],
            'target' => 1,
            'reward' => ['rosettes' => 10, 'materials' => ['wool' => 5, 'dye' => 5]],
        ],
        'living.shop' => [
            'chapter' => 'Making a living',
            'title' => 'Open for business',
            'story' => 'Your shop has six shelves. Price it right and the valley comes to you.',
            'goal' => 'Put an item on your shop\'s shelves.',
            'events' => ['list'],
            'target' => 1,
            'level' => 2,
            'reward' => ['corn' => 40, 'rosettes' => 10],
        ],
        'living.first_sale' => [
            'chapter' => 'Making a living',
            'title' => 'First customer',
            'story' => 'Corn in your pocket that you made yourself. Every sale also makes you a better trader.',
            'goal' => 'Sell an item to another animal.',
            'events' => ['sale'],
            'target' => 1,
            'level' => 2,
            'reward' => ['corn' => 80, 'rosettes' => 15],
        ],
        'power.fight' => [
            'chapter' => 'Power',
            'title' => 'Hold the line',
            'story' => 'Land changes hands at the end of a raid. Ironfield\'s men try the gate on Gate Night.',
            'goal' => 'Join a raid or a defence, or hold Ironfield Gate.',
            'events' => ['battle'],
            'target' => 1,
            'reward' => ['corn' => 50, 'rosettes' => 15],
        ],
        'power.buyer' => [
            'chapter' => 'Power',
            'title' => 'Know the market',
            'story' => 'The best traders know what everyone else is selling, and for how much.',
            'goal' => 'Buy 3 items from other animals\' shops.',
            'events' => ['buy'],
            'target' => 3,
            'reward' => ['corn' => 60, 'rosettes' => 15],
        ],
        'power.mogul' => [
            'chapter' => 'Power',
            'title' => 'A name in the square',
            'story' => 'Ten sales and the valley knows your shop. The House taxes the rich, but the rich can buy the House.',
            'goal' => 'Sell 10 items.',
            'events' => ['sale'],
            'target' => 10,
            'level' => 2,
            'reward' => ['corn' => 200, 'rosettes' => 30],
        ],
    ];

    /**
     * Daily jobs. "for" says who gets one: everyone, faction members, lone
     * wolves, makers with an approved design, or shopkeepers.
     *
     * @var array<string, array{title: string, goal: string, events: list<string>, target: int, for: string, reward: array{corn?: int, rosettes?: int, horseshoes?: int, materials?: array<string, int>}}>
     */
    private const DAILY = [
        'daily.gather' => ['title' => 'Bring it in', 'goal' => 'Gather 5 times.', 'events' => ['gather'], 'target' => 5, 'for' => 'all', 'reward' => ['corn' => 30, 'rosettes' => 5]],
        'daily.walk' => ['title' => 'Beat the bounds', 'goal' => 'Walk 4 regions.', 'events' => ['walk'], 'target' => 4, 'for' => 'all', 'reward' => ['corn' => 20, 'rosettes' => 5, 'horseshoes' => 1]],
        'daily.chat' => ['title' => 'Word in the square', 'goal' => 'Write 2 messages in chat.', 'events' => ['chat'], 'target' => 2, 'for' => 'all', 'reward' => ['corn' => 20, 'rosettes' => 5]],
        'daily.design' => ['title' => 'Fresh idea', 'goal' => 'Design a new item.', 'events' => ['design'], 'target' => 1, 'for' => 'all', 'reward' => ['corn' => 25, 'rosettes' => 5]],
        'daily.buy' => ['title' => 'Window shopping', 'goal' => 'Buy an item from another animal.', 'events' => ['buy'], 'target' => 1, 'for' => 'all', 'reward' => ['corn' => 30, 'rosettes' => 5]],
        'daily.give' => ['title' => 'For the store', 'goal' => 'Give 10 to your faction\'s store.', 'events' => ['give'], 'target' => 10, 'for' => 'faction', 'reward' => ['corn' => 30, 'rosettes' => 5]],
        'daily.battle' => ['title' => 'Call to arms', 'goal' => 'Join a raid or a defence.', 'events' => ['battle'], 'target' => 1, 'for' => 'faction', 'reward' => ['corn' => 40, 'rosettes' => 8]],
        'daily.crime' => ['title' => 'Night work', 'goal' => 'Commit a crime.', 'events' => ['crime'], 'target' => 1, 'for' => 'lone', 'reward' => ['corn' => 40, 'rosettes' => 8]],
        'daily.craft' => ['title' => 'At the bench', 'goal' => 'Make 2 copies of your designs.', 'events' => ['craft'], 'target' => 2, 'for' => 'maker', 'reward' => ['corn' => 30, 'rosettes' => 5, 'materials' => ['wood' => 3, 'wool' => 3]]],
        'daily.sell' => ['title' => 'Busy shop', 'goal' => 'Sell 2 items.', 'events' => ['sale'], 'target' => 2, 'for' => 'shop', 'reward' => ['corn' => 50, 'rosettes' => 8]],
    ];

    /** The jobs each species is best at, so its day suits it. */
    private const FAVOURITES = [
        'pig' => ['daily.sell', 'daily.battle', 'daily.buy'],
        'dog' => ['daily.battle', 'daily.walk'],
        'horse' => ['daily.craft', 'daily.give'],
        'sheep' => ['daily.chat', 'daily.give'],
        'goat' => ['daily.design', 'daily.buy'],
        'hen' => ['daily.craft', 'daily.gather'],
        'donkey' => ['daily.walk', 'daily.chat'],
        'raven' => ['daily.walk', 'daily.crime'],
    ];

    /**
     * Count something a player did towards their open missions.
     */
    public static function record(Player $player, string $event, int $amount = 1): void
    {
        if ($amount < 1) {
            return;
        }

        self::ensure($player);

        PlayerMission::query()
            ->where('player_id', $player->id)
            ->whereNull('completed_at')
            ->whereIn('period', ['story', Clock::today()])
            ->get()
            ->each(function (PlayerMission $mission) use ($player, $event, $amount) {
                $definition = self::definition($mission->key);

                if ($definition === null || ! in_array($event, $definition['events'], true)) {
                    return;
                }

                if (isset($definition['level']) && $player->level() < $definition['level']) {
                    return;
                }

                $mission->progress = min($mission->target, $mission->progress + $amount);

                if ($mission->progress >= $mission->target) {
                    $mission->completed_at = CarbonImmutable::now();
                }

                $mission->save();
            });
    }

    /**
     * Collect a finished mission's reward. Finishing a story step opens the next.
     *
     * @return array{corn?: int, rosettes?: int, horseshoes?: int, materials?: array<string, int>}
     *
     * @throws GameException
     */
    public static function claim(Player $player, PlayerMission $mission): array
    {
        $reward = DB::transaction(function () use ($player, $mission) {
            $mission = PlayerMission::query()->lockForUpdate()->findOrFail($mission->id);
            $definition = self::definition($mission->key);

            if ($mission->player_id !== $player->id || $definition === null) {
                throw new GameException("That isn't one of your missions.");
            }

            if ($mission->claimed_at !== null) {
                throw new GameException("You've already collected that reward.");
            }

            if ($mission->completed_at === null) {
                throw new GameException('Finish the mission first: '.lcfirst($definition['goal']));
            }

            if (! $mission->isStory() && $mission->period !== Clock::today()) {
                throw new GameException("Yesterday's jobs can't be collected any more.");
            }

            $player = Players::lock($player);
            $reward = $definition['reward'];

            $player->corn += $reward['corn'] ?? 0;

            if (isset($reward['materials'])) {
                $materials = $player->materials;

                foreach ($reward['materials'] as $material => $count) {
                    $materials[$material] = $player->material($material) + $count;
                }

                $player->materials = $materials;
            }

            $player->save();

            if (isset($reward['horseshoes'])) {
                Horseshoes::give($player, $reward['horseshoes']);
            }

            if (isset($reward['rosettes'])) {
                Rosettes::award($player, $reward['rosettes'], "Mission: {$definition['title']}", "mission:{$mission->id}", $player->valley->currentSeason());
            }

            $mission->forceFill(['claimed_at' => CarbonImmutable::now()])->save();

            return $reward;
        });

        self::ensure($player);

        return $reward;
    }

    /**
     * Everything the Today screen shows: where the player is in the story, and today's jobs.
     *
     * @return array{story: array<string, mixed>|null, chapters: list<array{name: string, done: int, total: int}>, daily: list<array<string, mixed>>}
     */
    public static function board(Player $player): array
    {
        self::ensure($player);

        $rows = PlayerMission::query()
            ->where('player_id', $player->id)
            ->whereIn('period', ['story', Clock::today()])
            ->orderBy('id')
            ->get();

        $story = $rows->where('period', 'story');
        $current = $story->first(fn (PlayerMission $mission) => $mission->claimed_at === null);
        $claimed = $story->whereNotNull('claimed_at')->pluck('key')->all();

        $chapters = collect(self::STORY)
            ->groupBy('chapter', preserveKeys: true)
            ->map(fn (Collection $steps, string $name) => [
                'name' => $name,
                'done' => $steps->keys()->intersect($claimed)->count(),
                'total' => $steps->count(),
            ])
            ->values()
            ->all();

        return [
            'story' => $current ? self::present($player, $current) : null,
            'chapters' => $chapters,
            'daily' => $rows->where('period', Clock::today())
                ->map(fn (PlayerMission $mission) => self::present($player, $mission))
                ->values()
                ->all(),
        ];
    }

    /** How many finished missions are waiting to be collected. */
    public static function waiting(Player $player): int
    {
        return PlayerMission::query()
            ->where('player_id', $player->id)
            ->whereNotNull('completed_at')
            ->whereNull('claimed_at')
            ->whereIn('period', ['story', Clock::today()])
            ->count();
    }

    /**
     * Make sure the player has their current story step and today's jobs.
     */
    public static function ensure(Player $player): void
    {
        $story = PlayerMission::query()->where('player_id', $player->id)->where('period', 'story')->get();

        if ($story->whereNull('claimed_at')->isEmpty()) {
            $next = collect(array_keys(self::STORY))->first(fn (string $key) => ! $story->contains('key', $key));

            if ($next !== null) {
                PlayerMission::query()->firstOrCreate(
                    ['player_id' => $player->id, 'key' => $next, 'period' => 'story'],
                    ['target' => self::STORY[$next]['target']],
                );
            }
        }

        $today = Clock::today();

        if (! PlayerMission::query()->where('player_id', $player->id)->where('period', $today)->exists()) {
            foreach (self::pick($player, $today) as $key) {
                PlayerMission::query()->firstOrCreate(
                    ['player_id' => $player->id, 'key' => $key, 'period' => $today],
                    ['target' => self::DAILY[$key]['target']],
                );
            }
        }
    }

    /**
     * Today's three jobs for a player: one its species is good at, when it can
     * do one, and two more, the same all day.
     *
     * @return list<string>
     */
    public static function pick(Player $player, string $day): array
    {
        $eligible = collect(self::DAILY)->filter(fn (array $job) => self::eligible($player, $job['for']))->keys();
        $order = fn (string $key) => crc32("{$player->id}|{$day}|{$key}");
        $favourites = $eligible->intersect(self::FAVOURITES[$player->species->value] ?? [])->sortBy($order)->values();
        $first = $favourites->take(1);

        return $first
            ->concat($eligible->diff($first)->sortBy($order)->values())
            ->take(self::DAILY_JOBS)
            ->values()
            ->all();
    }

    /**
     * @return array{chapter?: string, title: string, story?: string, goal: string, events: list<string>, target: int, reward: array<string, mixed>, level?: int, for?: string}|null
     */
    public static function definition(string $key): ?array
    {
        return self::STORY[$key] ?? self::DAILY[$key] ?? null;
    }

    /** @return list<string> */
    public static function storyKeys(): array
    {
        return array_keys(self::STORY);
    }

    /** @return list<string> */
    public static function dailyKeys(): array
    {
        return array_keys(self::DAILY);
    }

    private static function eligible(Player $player, string $for): bool
    {
        return match ($for) {
            'faction' => $player->faction_id !== null,
            'lone' => $player->faction_id === null && $player->level() >= (int) config('game.levels.crime'),
            'maker' => $player->designs()->where('status', 'approved')->exists(),
            'shop' => $player->level() >= (int) config('game.levels.stall'),
            default => true,
        };
    }

    /** @return array<string, mixed> */
    private static function present(Player $player, PlayerMission $mission): array
    {
        $definition = self::definition($mission->key);
        $level = $definition['level'] ?? null;

        return [
            'id' => $mission->id,
            'key' => $mission->key,
            'chapter' => $definition['chapter'] ?? null,
            'title' => $definition['title'],
            'story' => $definition['story'] ?? null,
            'goal' => $definition['goal'],
            'progress' => $mission->progress,
            'target' => $mission->target,
            'done' => $mission->completed_at !== null,
            'claimed' => $mission->claimed_at !== null,
            'locked' => $level !== null && $player->level() < $level ? "Opens at level {$level}." : null,
            'reward' => $definition['reward'],
            'favourite' => in_array($mission->key, self::FAVOURITES[$player->species->value] ?? [], true),
        ];
    }

    /** The species whose favourite jobs these are, for tests and the screens. */
    public static function favourites(Species $species): array
    {
        return self::FAVOURITES[$species->value] ?? [];
    }
}
