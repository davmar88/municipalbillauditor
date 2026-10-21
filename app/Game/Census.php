<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who is playing: animals online now (seen in the last few minutes), how
 * many live in each valley, their factions, and what the valley holds. For
 * the landing page's table and the game's "Who's online".
 */
final class Census
{
    /** An animal counts as online this long after it was last seen. */
    public const ONLINE_MINUTES = 5;

    /** Mark an animal as seen now, at most once a minute. */
    public static function seen(Player $player): void
    {
        if ($player->last_seen_at !== null && $player->last_seen_at->gt(CarbonImmutable::now()->subMinute())) {
            return;
        }

        Player::query()->whereKey($player->id)->update(['last_seen_at' => CarbonImmutable::now()]);
    }

    private static function since(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes(self::ONLINE_MINUTES);
    }

    public static function online(Valley $valley): int
    {
        return Player::query()->where('valley_id', $valley->id)->where('last_seen_at', '>=', self::since())->count();
    }

    /**
     * The animals online in a valley, with their faction and where they are.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function onlineIn(Valley $valley): Collection
    {
        return Player::query()
            ->with(['faction:id,name,colour', 'region:id,name'])
            ->where('valley_id', $valley->id)
            ->where('last_seen_at', '>=', self::since())
            ->orderByDesc('last_seen_at')
            ->limit(500)
            ->get()
            ->map(fn (Player $animal) => [
                'id' => $animal->id,
                'name' => $animal->name,
                'species' => $animal->species->value,
                'level' => $animal->level(),
                'faction' => $animal->faction ? ['name' => $animal->faction->name, 'colour' => $animal->faction->colour] : null,
                // Animals hiding in their homes, or lying low in Irongate, don't say where.
                'where' => $animal->inside_home_at !== null || $animal->region?->isCity() ? null : $animal->region?->name,
            ]);
    }

    /**
     * Everyone in a valley for the chat slide-out: those online first, then
     * the rest by when they were last about.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function everyoneIn(Valley $valley, int $limit = 300): Collection
    {
        $since = self::since();

        return Player::query()
            ->with(['faction:id,name,colour', 'region:id,name'])
            ->where('valley_id', $valley->id)
            ->when(! ValleyAnimals::on(), fn ($query) => $query->where('npc', false))
            ->orderByRaw('last_seen_at IS NULL')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn (Player $animal) => [
                'id' => $animal->id,
                'name' => $animal->name,
                'species' => $animal->species->value,
                'level' => $animal->level(),
                'faction' => $animal->faction ? ['name' => $animal->faction->name, 'colour' => $animal->faction->colour] : null,
                'where' => $animal->inside_home_at !== null || $animal->region?->isCity() ? null : $animal->region?->name,
                'online' => $animal->last_seen_at !== null && $animal->last_seen_at->gte($since),
                // Run by the game itself, not a person.
                'npc' => (bool) $animal->npc,
                'lastSeen' => $animal->last_seen_at?->toIso8601String(),
            ]);
    }

    /**
     * Every valley at a glance, for the landing page: animals, online,
     * factions and what they hold. Kept for a minute.
     *
     * @return list<array<string, mixed>>
     */
    public static function valleys(): array
    {
        return Cache::remember('census:valleys', 60, function () {
            $since = self::since();
            $players = DB::table('players')
                ->where('npc', false)
                ->groupBy('valley_id')
                ->selectRaw('valley_id, COUNT(*) AS animals, SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) AS online, SUM(corn) AS corn, SUM(apples) AS apples', [$since])
                ->get()
                ->keyBy('valley_id');

            return Valley::query()->orderBy('id')->get()->map(function (Valley $valley) use ($players) {
                $row = $players[$valley->id] ?? null;
                $factions = Faction::query()
                    ->where('valley_id', $valley->id)
                    ->withCount(['members', 'regions'])
                    ->orderByDesc('regions_count')
                    ->orderByDesc('members_count')
                    ->get();
                $materials = self::materials($valley, $factions);

                return [
                    'name' => $valley->name,
                    'animals' => (int) ($row->animals ?? 0),
                    'online' => (int) ($row->online ?? 0),
                    'house' => $valley->house()?->name,
                    // Last week's rivalry winner, and the leader this week.
                    'weekWinner' => Rivalry::lastWinner($valley->id)?->only(['name', 'colour', 'points']),
                    'weekLeader' => collect(Rivalry::board($valley))->first(fn (array $row) => $row['points'] > 0),
                    'corn' => (int) ($row->corn ?? 0) + (int) $factions->sum('treasury'),
                    'apples' => (int) ($row->apples ?? 0) + (int) $factions->sum('apples'),
                    'materials' => $materials,
                    'factions' => $factions->map(fn (Faction $faction) => [
                        'name' => $faction->name,
                        'colour' => $faction->colour,
                        'members' => $faction->members_count,
                        'land' => $faction->regions_count,
                    ])->values()->all(),
                ];
            })->all();
        });
    }

    /**
     * Every material in a valley, carried by animals and in faction stores.
     *
     * @param  Collection<int, Faction>  $factions
     * @return array<string, int>
     */
    private static function materials(Valley $valley, Collection $factions): array
    {
        $total = [];

        foreach ([...Player::query()->where('valley_id', $valley->id)->whereNotNull('materials')->pluck('materials')->all(), ...$factions->pluck('materials')->all()] as $held) {
            foreach ((array) $held as $name => $amount) {
                $total[$name] = ($total[$name] ?? 0) + (int) $amount;
            }
        }

        arsort($total);

        return array_filter($total, fn (int $amount) => $amount > 0);
    }
}
