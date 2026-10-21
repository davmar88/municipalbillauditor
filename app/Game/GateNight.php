<?php

namespace App\Game;

use App\Models\GateDefence;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every Sunday at 19:00 Ironfield's men raid the valley. All day Sunday any
 * animal, whatever its faction, can put horseshoes into holding the gate.
 * If the gate holds, every defender earns Rosettes; if not, the humans burn
 * two outer fields and the valley grows angry. Either way the whole valley
 * hears how it went.
 */
final class GateNight
{
    public const HOUR = 19;

    /** Ironfield never sends fewer men than this, before the factions study. */
    public const MIN_HUMANS = 6;

    public static function isGateDay(): bool
    {
        return Clock::now()->isSunday() && Clock::now()->hour < self::HOUR;
    }

    /**
     * The men Ironfield sends: six, and one more for every study level the
     * valley's strongest faction has, but never fewer than two for every
     * animal active this week.
     */
    public static function humans(Valley $valley): int
    {
        return max(self::MIN_HUMANS + Research::topPoints($valley->id), 2 * Players::activeCount($valley));
    }

    /**
     * Who is at the gate tonight and how many men they hold back between
     * them, against the men coming.
     *
     * @return array{defenders: int, strength: float, humans: int}
     */
    public static function tonight(Season $season): array
    {
        $defences = self::defences($season, Clock::today());

        return [
            'defenders' => $defences->count(),
            'strength' => round(self::strength($defences), 1),
            'humans' => self::humans($season->valley),
        ];
    }

    /**
     * How tonight's raid went, once it has happened.
     *
     * @return array{held: bool, defenders: int, burned: list<string>}|null
     */
    public static function result(Season $season): ?array
    {
        return $season->last_gate_night_on?->toDateString() === Clock::today() ? $season->last_gate_night : null;
    }

    /** @return Collection<int, GateDefence> */
    private static function defences(Season $season, string $night): Collection
    {
        return GateDefence::query()->with('player')->where('season_id', $season->id)->where('night_on', $night)->get();
    }

    /** @param  Collection<int, GateDefence>  $defences */
    private static function strength(Collection $defences): float
    {
        // A faction's Defence makes its members stronger at the gate too.
        return (float) $defences->sum(fn (GateDefence $d) => $d->horseshoes * $d->player->species->defence() * Research::defence($d->player->faction_id));
    }

    /**
     * @throws GameException
     */
    public function defend(Player $player, int $horseshoes): GateDefence
    {
        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');

        if (! self::isGateDay()) {
            throw new GameException('The gate is defended on Sundays before 19:00.');
        }

        if ($horseshoes < 1 || $horseshoes > 3) {
            throw new GameException('Put in 1 to 3 horseshoes.');
        }

        $night = Clock::today();

        if (GateDefence::query()->where('season_id', $season->id)->where('player_id', $player->id)->where('night_on', $night)->exists()) {
            throw new GameException("You're already at the gate tonight.");
        }

        return DB::transaction(function () use ($player, $season, $horseshoes, $night) {
            $locked = Players::lock($player);
            Players::ensureFree($locked);
            Horseshoes::spend($locked, $horseshoes);

            $defence = GateDefence::create(['season_id' => $season->id, 'player_id' => $locked->id, 'night_on' => $night, 'horseshoes' => $horseshoes]);
            Missions::record($locked, 'battle');

            return $defence;
        });
    }

    /** The raid itself, once on Sunday evening. */
    public function resolveIfDue(Season $season, Wall $wall): void
    {
        $now = Clock::now();

        if (! $now->isSunday() || $now->hour < self::HOUR) {
            return;
        }

        $night = $now->toDateString();

        if ($season->last_gate_night_on?->toDateString() === $night) {
            return;
        }

        DB::transaction(function () use ($season, $wall, $night) {
            $season->forceFill(['last_gate_night_on' => $night])->save();

            $defences = self::defences($season, $night);
            $humans = self::humans($season->valley);
            $won = self::strength($defences) >= $humans;

            foreach ($defences as $defence) {
                Rosettes::award($defence->player, ($won ? 15 : 5) * Finale::boost($season), $won ? 'Held the gate' : 'Fought at the gate', "gate:{$night}:{$defence->player_id}", $season);
            }

            $burned = $won ? collect() : Region::query()
                ->where('valley_id', $season->valley_id)
                ->where('ring', 'outer')
                ->whereNotNull('faction_id')
                ->orderBy('held_since')
                ->limit(2)
                ->get()
                ->each(fn (Region $region) => $region->forceFill(['faction_id' => null, 'held_since' => null, 'settled_until' => CarbonImmutable::now()->addHour()])->save());

            $names = $burned->pluck('name')->values()->all();
            $season->forceFill(['last_gate_night' => ['held' => $won, 'defenders' => $defences->count(), 'burned' => $names]])->save();

            $defenders = $defences->pluck('player_id');
            $others = Notices::valley($season->valley_id)->diff($defenders);
            $map = route('play', [], false);
            $count = $defences->count() === 1 ? '1 defender' : "{$defences->count()} defenders";

            if ($won) {
                $wall->stir($season, -5, 'The gate held against Ironfield', 24);
                Notices::send($defenders, 'gate', "🛡 The gate held against Ironfield's {$humans} men at 19:00! You earned 15 Rosettes for defending it.", $map);
                Notices::send($others, 'gate', "🛡 The gate held against Ironfield's {$humans} men at 19:00, thanks to its {$count}.", $map);

                return;
            }

            $damage = $names === [] ? 'No field beyond the fences was held, so nothing burned.' : 'They burned '.implode(' and ', $names).'.';
            $wall->stir($season, 5, $names === [] ? "Ironfield's men broke through the gate" : "Ironfield's men burned the outer fields", 48);
            Notices::send($defenders, 'gate', "🔥 Ironfield's {$humans} men broke through the gate at 19:00. {$damage} You earned 5 Rosettes for fighting.", $map);
            Notices::send($others, 'gate', "🔥 Ironfield's {$humans} men broke through the gate at 19:00".($defences->isEmpty() ? ', with nobody defending it' : ", past its {$count}").". {$damage}", $map);
        });
    }
}
