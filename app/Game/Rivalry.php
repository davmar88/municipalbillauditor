<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Models\Attack;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\HallOfFame;
use App\Models\Region;
use App\Models\Valley;
use Carbon\CarbonImmutable;

/**
 * The weekly rivalry: every faction scored on raids won, Corn stolen and
 * land held, from Monday to Sunday. Each Monday last week's winner goes
 * into the Hall of Fame and the valley hears about it.
 */
final class Rivalry
{
    public const RAID_POINTS = 10;

    public const LAND_POINTS = 5;

    /** Corn stolen per point. */
    public const CORN_PER_POINT = 10;

    public static function weekStart(?CarbonImmutable $at = null): CarbonImmutable
    {
        return Clock::local($at ?? CarbonImmutable::now())->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * Each faction's score for a week, best first.
     *
     * @return list<array{id: int, name: string, colour: string, raids: int, stolen: int, land: int, points: int}>
     */
    public static function board(Valley $valley, ?CarbonImmutable $weekStart = null): array
    {
        $from = $weekStart ?? self::weekStart();
        $to = $from->addWeek();
        $factions = Faction::query()->where('valley_id', $valley->id)->get();
        $ids = $factions->pluck('id');

        $raids = Attack::query()->whereIn('faction_id', $ids)->where('winner', 'attack')
            ->whereBetween('resolved_at', [$from, $to])
            ->selectRaw('faction_id, count(*) as won')->groupBy('faction_id')->pluck('won', 'faction_id');

        // Corn and goods carried off by members' robberies and lootings, counted by what the crime took.
        $stolen = Crime::query()
            ->join('players', 'players.id', '=', 'crimes.player_id')
            ->whereIn('players.faction_id', $ids)
            ->whereIn('crimes.kind', [CrimeKind::Rob->value, CrimeKind::Loot->value])
            ->where(fn ($free) => $free->where('crimes.caught', false)->orWhereNull('crimes.caught'))
            ->whereBetween('crimes.resolved_at', [$from, $to])
            ->selectRaw('players.faction_id as faction_id, sum(crimes.loot) as taken')
            ->groupBy('players.faction_id')
            ->pluck('taken', 'faction_id');

        $land = Region::query()->where('valley_id', $valley->id)->whereIn('faction_id', $ids)
            ->selectRaw('faction_id, count(*) as held')->groupBy('faction_id')->pluck('held', 'faction_id');

        return $factions->map(function (Faction $faction) use ($raids, $stolen, $land) {
            $row = [
                'id' => $faction->id,
                'name' => $faction->name,
                'colour' => $faction->colour,
                'raids' => (int) ($raids[$faction->id] ?? 0),
                'stolen' => (int) ($stolen[$faction->id] ?? 0),
                'land' => (int) ($land[$faction->id] ?? 0),
            ];
            $row['points'] = $row['raids'] * self::RAID_POINTS + $row['land'] * self::LAND_POINTS + intdiv($row['stolen'], self::CORN_PER_POINT);

            return $row;
        })
            ->sortByDesc('points')
            ->values()
            ->all();
    }

    /** On a new week, put last week's top faction in the Hall of Fame. Safe to run every minute. */
    public static function settleWeek(Valley $valley, int $seasonNumber): void
    {
        $last = self::weekStart()->subWeek();

        if (HallOfFame::query()->where('valley_id', $valley->id)->where('kind', 'week')->whereDate('week_of', $last->toDateString())->exists()) {
            return;
        }

        $top = self::board($valley, $last)[0] ?? null;

        HallOfFame::create([
            'valley_id' => $valley->id,
            'kind' => 'week',
            'season_number' => $seasonNumber,
            'faction_id' => $top !== null && $top['points'] > 0 ? $top['id'] : null,
            'name' => $top !== null && $top['points'] > 0 ? $top['name'] : 'Nobody',
            'colour' => $top['colour'] ?? null,
            'points' => $top['points'] ?? 0,
            'week_of' => $last->toDateString(),
        ]);

        if ($top !== null && $top['points'] > 0) {
            Notices::send(Notices::valley($valley->id), 'season', "🏅 {$top['name']} won last week's rivalry with {$top['points']} points.", route('play.valley', [], false));
        }
    }

    /** Last week's winner, for the landing page and the Valley page. */
    public static function lastWinner(int $valleyId): ?HallOfFame
    {
        return HallOfFame::query()->where('valley_id', $valleyId)->where('kind', 'week')->whereNotNull('faction_id')->latest('week_of')->first();
    }
}
