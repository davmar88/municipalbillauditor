<?php

namespace App\Game;

use App\Models\GateDefence;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every Sunday at 19:00 Ironfield's men raid the valley. All day Sunday any
 * animal, whatever its faction, can put horseshoes into holding the gate.
 * If the gate holds, every defender earns Rosettes; if not, the humans burn
 * two outer fields and the valley grows angry.
 */
final class GateNight
{
    public const HOUR = 19;

    public static function isGateDay(): bool
    {
        return Clock::now()->isSunday() && Clock::now()->hour < self::HOUR;
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

            $defences = GateDefence::query()->with('player')->where('season_id', $season->id)->where('night_on', $night)->get();
            $humans = max(10, 2 * Players::activeCount($season->valley));
            $held = $defences->sum(fn (GateDefence $d) => $d->horseshoes * $d->player->species->defence());
            $won = $held >= $humans;

            foreach ($defences as $defence) {
                Rosettes::award($defence->player, $won ? 15 : 5, $won ? 'Held the gate' : 'Fought at the gate', "gate:{$night}:{$defence->player_id}", $season);
            }

            if ($won) {
                $wall->stir($season, -5, 'The gate held against Ironfield', 24);

                return;
            }

            Region::query()
                ->where('valley_id', $season->valley_id)
                ->where('ring', 'outer')
                ->whereNotNull('faction_id')
                ->orderBy('held_since')
                ->limit(2)
                ->get()
                ->each(fn (Region $region) => $region->forceFill(['faction_id' => null, 'held_since' => null, 'settled_until' => CarbonImmutable::now()->addHour()])->save());

            $wall->stir($season, 5, "Ironfield's men burned the outer fields", 48);
        });
    }
}
