<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The underworld. Lone wolves can loot a faction's treasury or burn down a
 * faction member's home. A crime settles at the next tick: dogs guarding
 * the target and the Night Watch make getting caught likelier. Crime never
 * takes anything bought with real money.
 */
final class Crimes
{
    public const LOOT_SHARE = 0.10;

    public const POUND_HOURS = 6;

    public const REBUILD_CORN = 100;

    /**
     * @throws GameException
     */
    public function commit(Player $player, CrimeKind $kind, ?Faction $faction = null, ?Player $target = null): Crime
    {
        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');
        $level = (int) config('game.levels.crime');

        if (! $player->isLoneWolf()) {
            throw new GameException('Only lone wolves commit crimes. Leave your faction first.');
        }

        if ($player->level() < $level) {
            throw new GameException("Crime opens up at level {$level}.");
        }

        if (Crime::query()->where('player_id', $player->id)->where('created_at', '>=', Clock::startOfToday())->exists()) {
            throw new GameException('You have already tried a crime today. Lie low until tomorrow.');
        }

        if ($kind === CrimeKind::Loot) {
            if ($faction === null || $faction->valley_id !== $player->valley_id) {
                throw new GameException('Pick a faction to loot.');
            }

            if (Crime::query()->where('target_faction_id', $faction->id)->where('created_at', '>=', Clock::startOfToday())->exists()) {
                throw new GameException("{$faction->name} has already been targeted today.");
            }
        } else {
            if ($target === null || $target->faction_id === null || $target->valley_id !== $player->valley_id) {
                throw new GameException("Pick a faction member's home to burn.");
            }

            if ($target->isShielded()) {
                throw new GameException("{$target->name} is new to the valley and protected for now.");
            }

            if ($target->homeIsClosed() || ($target->home_burned_at !== null && $target->home_burned_at->gt(CarbonImmutable::now()->subDays(7)))) {
                throw new GameException("{$target->name}'s home burned recently. A home can burn at most once a week.");
            }
        }

        return DB::transaction(function () use ($player, $kind, $faction, $target, $season) {
            $locked = Players::lock($player);
            Players::ensureFree($locked);
            Horseshoes::spend($locked, $kind->horseshoes());

            return Crime::create([
                'season_id' => $season->id,
                'player_id' => $locked->id,
                'kind' => $kind,
                'target_faction_id' => $kind === CrimeKind::Loot ? $faction?->id : $target?->faction_id,
                'target_player_id' => $target?->id,
                'settles_at' => CarbonImmutable::now()->addMinutes(30),
                'seed' => Dice::seed(),
            ]);
        });
    }

    public function resolveDue(Season $season): void
    {
        $season->crimes()
            ->whereNull('resolved_at')
            ->where('settles_at', '<=', CarbonImmutable::now())
            ->get()
            ->each(fn (Crime $crime) => $this->resolve($season, $crime));
    }

    public function resolve(Season $season, Crime $crime): void
    {
        DB::transaction(function () use ($season, $crime) {
            $crime = Crime::query()->lockForUpdate()->findOrFail($crime->id);

            if ($crime->resolved_at !== null) {
                return;
            }

            $criminal = Players::lock($crime->player);
            $chance = $this->catchChance($season, $crime);
            $caught = Dice::roll($crime->seed, 'caught') < $chance;
            $loot = 0;

            if ($caught) {
                $criminal->forceFill([
                    'pound_until' => CarbonImmutable::now()->addHours(self::POUND_HOURS),
                    'wanted' => $criminal->wanted + 1,
                ])->save();
            } elseif ($crime->kind === CrimeKind::Loot && $crime->target_faction_id !== null) {
                $faction = Faction::query()->lockForUpdate()->find($crime->target_faction_id);

                if ($faction !== null) {
                    $loot = (int) floor($faction->treasury * Bases::lootShare($faction));
                    $faction->decrement('treasury', $loot);
                    $criminal->increment('corn', $loot);
                }
            } elseif ($crime->kind === CrimeKind::Burn && $crime->target_player_id !== null) {
                Player::query()->whereKey($crime->target_player_id)->update([
                    'home_closed_until' => CarbonImmutable::now()->addDay(),
                    'home_burned_at' => CarbonImmutable::now(),
                ]);
            }

            $crime->forceFill(['resolved_at' => CarbonImmutable::now(), 'caught' => $caught, 'loot' => $loot])->save();
        });
    }

    /**
     * Rebuild a burned home straight away for 100 Corn.
     *
     * @throws GameException
     */
    public function rebuild(Player $player): void
    {
        DB::transaction(function () use ($player) {
            $player = Players::lock($player);

            if (! $player->homeIsClosed()) {
                throw new GameException('Your home is standing.');
            }

            if ($player->corn < self::REBUILD_CORN) {
                throw new GameException('Rebuilding costs '.self::REBUILD_CORN." Corn. You have {$player->corn}.");
            }

            $player->corn -= self::REBUILD_CORN;
            $player->home_closed_until = null;
            $player->save();
        });
    }

    public function catchChance(Season $season, Crime $crime): float
    {
        $dogs = $crime->target_faction_id === null ? 0 : Player::query()
            ->where('faction_id', $crime->target_faction_id)
            ->where('species', Species::Dog)
            ->count();

        $watch = Wall::law($season, LawTemplate::NightWatch) !== null ? 0.25 : 0.0;

        return min(0.9, 0.3 + 0.05 * min(4, $dogs) + $watch);
    }
}
