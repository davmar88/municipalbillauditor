<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use App\Models\PlunderBand;
use App\Models\PlunderFighter;
use App\Models\Region;
use App\Models\Season;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Irongate's plunderers. Every few hours in daylight a band rides out of
 * the town for a farm a faction holds, and the whole valley hears where and
 * when it strikes. Any faction's animals can fight it from wherever they
 * are, putting in horseshoes; factions fight side by side against the
 * humans. Drive the band off and the fighters share what it carried; lose
 * and it sacks the farm's holders, and the fighters are laid up a while
 * (less with a Hospital).
 */
final class Plunderers
{
    /** How long between one band and the next. */
    public const EVERY_HOURS = 6;

    /** Bands ride in daylight: from this hour ... */
    public const FROM_HOUR = 8;

    /** ... until this one. */
    public const UNTIL_HOUR = 21;

    /** How long a band takes to reach its farm: time to rally. */
    public const MARCH_MINUTES = 60;

    public const MIN_MEN = 4;

    public const MAX_MEN = 24;

    /** What each plunderer carries, in Corn: the fighters' share when the band is beaten. */
    public const CORN_PER_MAN = 15;

    /** The share of the holders' treasury a band that wins carries off. */
    public const SACK_SHARE = 0.15;

    /** How long a beaten fighter is laid up, before any Hospital. */
    public const LAID_UP_MINUTES = 60;

    /** The band riding now, if any. */
    public static function riding(int $seasonId): ?PlunderBand
    {
        return PlunderBand::query()->with(['region', 'faction'])->where('season_id', $seasonId)->whereNull('resolved_at')->latest('id')->first();
    }

    /** How many men ride: more as the valley fills, at least a few. */
    public static function menFor(int $valleyId): int
    {
        return max(self::MIN_MEN, min(self::MAX_MEN, 3 + (int) ceil(Players::activeCount(Valley::query()->findOrFail($valleyId)) / 2)));
    }

    /** The animals' strength against a band: each horseshoe, by species, faction studies and the farm's own defences for its holders. */
    public static function strength(PlunderBand $band): float
    {
        $region = $band->region;

        return (float) $band->fighters()->with('player')->get()->sum(function (PlunderFighter $fighter) use ($region) {
            $holder = $fighter->faction_id !== null && $fighter->faction_id === $region->faction_id;
            $animal = $fighter->player->species;

            return $fighter->horseshoes
                * ($holder ? $animal->defence() * Bases::defence($region) : $animal->attack())
                * Research::defence($fighter->faction_id);
        });
    }

    /**
     * Send a band out of Irongate, if it's time: daylight, none riding, the
     * last long enough ago, and a held farm to ride for.
     */
    public function muster(Season $season, ?int $seed = null): ?PlunderBand
    {
        $now = Clock::now();

        if ($now->hour < self::FROM_HOUR || $now->hour >= self::UNTIL_HOUR) {
            return null;
        }

        $last = PlunderBand::query()->where('season_id', $season->id)->latest('id')->first();

        if ($last !== null && ($last->resolved_at === null || $last->lands_at->gt(CarbonImmutable::now()->subHours(self::EVERY_HOURS)))) {
            return null;
        }

        // Not on the season's first hour: give the valley time to settle.
        if ($last === null && $season->starts_at->gt(CarbonImmutable::now()->subHour())) {
            return null;
        }

        $farms = Region::query()
            ->where('valley_id', $season->valley_id)
            ->where('ring', 'farm')
            ->where('wild', false)
            ->whereNotNull('faction_id')
            ->orderBy('id')
            ->get();

        if ($farms->isEmpty()) {
            return null;
        }

        $seed ??= Dice::seed();
        $target = $farms[(int) min($farms->count() - 1, floor(Dice::roll($seed, 'target') * $farms->count()))];

        return DB::transaction(function () use ($season, $target, $seed) {
            $band = PlunderBand::create([
                'season_id' => $season->id,
                'region_id' => $target->id,
                'faction_id' => $target->faction_id,
                'men' => self::menFor($season->valley_id),
                'lands_at' => CarbonImmutable::now()->addMinutes(self::MARCH_MINUTES),
                'seed' => $seed,
            ]);

            $at = Clock::local($band->lands_at)->format('H:i');
            $holders = Faction::query()->whereKey($target->faction_id)->value('name');
            Notices::send(
                self::factionAnimals($season->valley_id),
                'raid',
                "🏴 {$band->men} plunderers rode out of Irongate for {$target->name}, held by {$holders}. They strike at {$at}: every faction can fight them from the map.",
                route('play', [], false),
            );

            return $band;
        });
    }

    /**
     * Put horseshoes into fighting the band: any animal in a faction, from
     * anywhere, once a band.
     *
     * @throws GameException
     */
    public function fight(Player $player, PlunderBand $band, int $horseshoes): PlunderFighter
    {
        if ($horseshoes < 1 || $horseshoes > 3) {
            throw new GameException('Put in 1 to 3 horseshoes.');
        }

        return DB::transaction(function () use ($player, $band, $horseshoes) {
            $band = PlunderBand::query()->lockForUpdate()->findOrFail($band->id);
            $player = Players::lock($player);
            Players::ensureFree($player);

            if ($band->resolved_at !== null || $band->lands_at->isPast()) {
                throw new GameException('That fight is over.');
            }

            if ($player->faction_id === null) {
                throw new GameException('Factions fight the plunderers. Join or found one to ride out with them.');
            }

            if (PlunderFighter::query()->where('plunder_band_id', $band->id)->where('player_id', $player->id)->exists()) {
                throw new GameException("You're already in this fight.");
            }

            Horseshoes::spend($player, $horseshoes);
            Missions::record($player, 'battle');
            Missions::record($player, 'plunderers');

            return PlunderFighter::create([
                'plunder_band_id' => $band->id,
                'player_id' => $player->id,
                'faction_id' => $player->faction_id,
                'horseshoes' => $horseshoes,
            ]);
        });
    }

    public function resolveDue(Season $season): void
    {
        PlunderBand::query()
            ->where('season_id', $season->id)
            ->whereNull('resolved_at')
            ->where('lands_at', '<=', CarbonImmutable::now())
            ->get()
            ->each(fn (PlunderBand $band) => $this->resolve($season, $band));
    }

    /** The band strikes: the fighters drive it off and share its haul, or it sacks the farm's holders. */
    public function resolve(Season $season, PlunderBand $band): void
    {
        DB::transaction(function () use ($season, $band) {
            $band = PlunderBand::query()->lockForUpdate()->findOrFail($band->id);

            if ($band->resolved_at !== null) {
                return;
            }

            $region = $band->region;
            $fighters = $band->fighters()->with('player')->get();
            $strength = self::strength($band) * Dice::roll($band->seed, 'animals', 0.85, 1.15);
            $men = $band->men * Dice::roll($band->seed, 'men', 0.9, 1.1);
            $won = $strength >= $men;
            $map = route('play', [], false);

            if ($won) {
                $haul = $band->men * self::CORN_PER_MAN;
                $shares = $fighters->sum('horseshoes');

                foreach ($fighters as $fighter) {
                    $corn = (int) floor($haul * $fighter->horseshoes / max(1, $shares));
                    Player::query()->whereKey($fighter->player_id)->increment('corn', $corn);
                    Rosettes::award($fighter->player, 12, "Drove off Irongate's plunderers at {$region->name}", "plunder:{$band->id}:{$fighter->player_id}", $season);
                    Notices::send($fighter->player_id, 'battle', "🏆 You drove off {$band->men} plunderers at {$region->name} and took {$corn} Corn from their packs.", $map);
                }
            } else {
                // The band sacks the holders: Corn from the treasury and a share of the store.
                $holders = $region->faction_id !== null ? Faction::query()->lockForUpdate()->find($region->faction_id) : null;
                $haul = 0;

                if ($holders !== null) {
                    $haul = (int) floor($holders->treasury * self::SACK_SHARE);
                    $materials = collect($holders->materials ?? [])->map(fn ($amount) => (int) $amount - (int) floor((int) $amount * self::SACK_SHARE))->all();
                    $holders->forceFill(['treasury' => $holders->treasury - $haul, 'materials' => $materials])->save();
                    Notices::send(Notices::members($holders->id), 'battle', "🏴 Irongate's plunderers sacked {$region->name} and carried off {$haul} Corn and a share of your store.", $map);
                }

                foreach ($fighters as $fighter) {
                    $player = Players::lock($fighter->player);
                    $minutes = (int) round(self::LAID_UP_MINUTES * (1 - Bases::healing($player->faction)));
                    $player->forceFill(['pound_until' => CarbonImmutable::now()->addMinutes($minutes)])->save();
                    Rosettes::award($player, 4, "Fought Irongate's plunderers at {$region->name}", "plunder:{$band->id}:{$fighter->player_id}", $season);
                    Notices::send($player->id, 'battle', "🤕 The plunderers beat you at {$region->name}. You're laid up until ".Clock::local($player->pound_until)->format('H:i').'.', $map);
                }
            }

            $band->forceFill([
                'resolved_at' => CarbonImmutable::now(),
                'won' => $won,
                'strength' => round($strength, 2),
                'haul' => $haul,
            ])->save();

            $fought = $fighters->count();
            Notices::send(
                // The holders heard of their losses above.
                self::factionAnimals($season->valley_id)->diff($fighters->pluck('player_id'))->diff($won ? [] : Notices::members($region->faction_id)),
                'battle',
                $won
                    ? "🛡 {$fought} animals drove Irongate's plunderers off {$region->name}."
                    : "🏴 Irongate's plunderers sacked {$region->name}".($fought > 0 ? ", beating the {$fought} animals who stood against them." : ': nobody rode out to fight them.'),
                $map,
            );
        });
    }

    /** @return Collection<int, int> Every animal in a faction, in the valley. */
    private static function factionAnimals(int $valleyId): Collection
    {
        return Player::query()->where('valley_id', $valleyId)->whereNotNull('faction_id')->pluck('id');
    }
}
