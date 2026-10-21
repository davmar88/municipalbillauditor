<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\HallOfFame;
use App\Models\Player;
use App\Models\Region;
use App\Models\RosetteEntry;
use App\Models\Season;
use App\Models\Valley;
use Illuminate\Support\Collection;

/**
 * The end of a season, made into an occasion (her ask, 2026-10-22): a
 * countdown, the final days when every battle's Rosettes count double, and
 * a podium. The faction holding the most land and the three animals with
 * the most Rosettes this season go into the Hall of Fame for good, with
 * Corn and Rosettes. No Apples: those are only ever bought.
 */
final class Finale
{
    /** The last days of a season, when battles pay double Rosettes. */
    public const FINAL_DAYS = 3;

    /** Corn and Rosettes for each member of the winning faction. */
    public const FACTION_PRIZE = ['corn' => 300, 'rosettes' => 100];

    /** Corn and Rosettes for the season's three best animals, first to third. */
    public const ANIMAL_PRIZES = [
        1 => ['corn' => 600, 'rosettes' => 150],
        2 => ['corn' => 300, 'rosettes' => 75],
        3 => ['corn' => 150, 'rosettes' => 40],
    ];

    public static function finalDays(?Season $season): bool
    {
        return $season !== null && $season->ends_at->isFuture() && $season->ends_at->lte(now()->addDays(self::FINAL_DAYS));
    }

    /** What a battle's Rosettes are multiplied by right now. */
    public static function boost(?Season $season): int
    {
        return config('game.valley_life.enabled') && self::finalDays($season) ? 2 : 1;
    }

    /**
     * The factions in the lead: most land, then the fullest treasury.
     *
     * @return Collection<int, array{faction: Faction, land: int}>
     */
    public static function factions(Valley $valley, int $take = 3): Collection
    {
        $land = Region::query()->where('valley_id', $valley->id)->whereNotNull('faction_id')
            ->selectRaw('faction_id, count(*) as held')->groupBy('faction_id')->pluck('held', 'faction_id');

        return Faction::query()->where('valley_id', $valley->id)->get()
            ->map(fn (Faction $faction) => ['faction' => $faction, 'land' => (int) ($land[$faction->id] ?? 0)])
            ->filter(fn (array $row) => $row['land'] > 0)
            ->sortBy([['land', 'desc'], [fn (array $a, array $b) => $b['faction']->treasury <=> $a['faction']->treasury]])
            ->take($take)
            ->values();
    }

    /**
     * The animals with the most Rosettes this season. Valley animals aren't ranked.
     *
     * @return Collection<int, array{player: Player, rosettes: int}>
     */
    public static function animals(Season $season, int $take = 3): Collection
    {
        $points = RosetteEntry::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', Player::query()->select('id')->where('valley_id', $season->valley_id)->where('npc', false))
            ->selectRaw('player_id, sum(amount) as total')
            ->groupBy('player_id')
            ->havingRaw('sum(amount) > 0')
            ->orderByDesc('total')
            ->limit($take)
            ->pluck('total', 'player_id');

        $players = Player::query()->with('faction:id,name,colour')->whereKey($points->keys())->get()->keyBy('id');

        return $points->map(fn ($total, int $id) => ['player' => $players[$id], 'rosettes' => (int) $total])->values();
    }

    /**
     * Who is on the podium as the season ends, before the map resets.
     *
     * @return array{faction: ?array{faction: Faction, land: int}, animals: Collection<int, array{player: Player, rosettes: int}>}
     */
    public static function podium(Valley $valley, Season $season): array
    {
        return ['faction' => self::factions($valley, 1)->first(), 'animals' => self::animals($season)];
    }

    /**
     * Put the podium in the Hall of Fame and pay the prizes, after the new
     * season has started so the Corn isn't cut by the reset.
     *
     * @param  array{faction: ?array{faction: Faction, land: int}, animals: Collection<int, array{player: Player, rosettes: int}>}  $podium
     */
    public static function crown(Valley $valley, Season $ended, Season $next, array $podium): void
    {
        $everyone = Notices::valley($valley->id);
        $lines = [];

        if ($podium['faction'] !== null) {
            $faction = $podium['faction']['faction'];
            HallOfFame::create([
                'valley_id' => $valley->id, 'kind' => 'season_faction', 'season_number' => $ended->number, 'place' => 1,
                'faction_id' => $faction->id, 'name' => $faction->name, 'colour' => $faction->colour, 'points' => $podium['faction']['land'],
            ]);

            foreach ($faction->members()->get() as $member) {
                self::pay($member, self::FACTION_PRIZE, "Season {$ended->number} champions", "finale:{$ended->id}:faction:{$member->id}", $next);
            }

            $lines[] = "👑 {$faction->name} won season {$ended->number}, holding {$podium['faction']['land']} regions.";
        }

        foreach ($podium['animals'] as $i => $row) {
            $place = $i + 1;
            $player = $row['player'];
            HallOfFame::create([
                'valley_id' => $valley->id, 'kind' => 'season_animal', 'season_number' => $ended->number, 'place' => $place,
                'player_id' => $player->id, 'faction_id' => $player->faction_id, 'name' => $player->name, 'colour' => $player->faction?->colour, 'points' => $row['rosettes'],
            ]);
            self::pay($player, self::ANIMAL_PRIZES[$place], "Season {$ended->number}, place {$place}", "finale:{$ended->id}:animal:{$player->id}", $next);
        }

        if ($podium['animals']->isNotEmpty()) {
            $medals = ['🥇', '🥈', '🥉'];
            $lines[] = $podium['animals']->map(fn (array $row, int $i) => "{$medals[$i]} {$row['player']->name}")->implode(' ');
        }

        if ($lines !== []) {
            Notices::send($everyone, 'season', implode(' ', $lines).' Season '.$next->number.' has begun.', route('play.valley', [], false));
        }
    }

    /**
     * The Hall of Fame, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function hall(int $valleyId): array
    {
        return HallOfFame::query()
            ->where('valley_id', $valleyId)
            ->whereIn('kind', ['season_faction', 'season_animal'])
            ->orderByDesc('season_number')
            ->orderBy('kind')
            ->orderBy('place')
            ->limit(60)
            ->get()
            ->groupBy('season_number')
            ->map(fn (Collection $rows, int $number) => [
                'season' => $number,
                'faction' => ($top = $rows->firstWhere('kind', 'season_faction')) ? ['id' => $top->faction_id, 'name' => $top->name, 'colour' => $top->colour, 'land' => $top->points] : null,
                'animals' => $rows->where('kind', 'season_animal')->values()->map(fn (HallOfFame $row) => [
                    'id' => $row->player_id, 'name' => $row->name, 'colour' => $row->colour, 'place' => $row->place, 'rosettes' => $row->points,
                ])->all(),
            ])
            ->values()
            ->all();
    }

    /** @param  array{corn: int, rosettes: int}  $prize */
    private static function pay(Player $player, array $prize, string $reason, string $reference, Season $season): void
    {
        Player::query()->whereKey($player->id)->increment('corn', $prize['corn']);
        Rosettes::award($player, $prize['rosettes'], $reason, $reference, $season);
        Notices::send($player->id, 'season', "🏆 {$reason}: you won {$prize['corn']} Corn and {$prize['rosettes']} Rosettes.", route('play.valley', [], false));
    }
}
