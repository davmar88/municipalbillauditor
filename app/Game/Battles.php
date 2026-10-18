<?php

namespace App\Game;

use App\Enums\Species;
use App\Models\Attack;
use App\Models\BattleCommitment;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Raids between factions. An attack marches for an hour (two on the
 * Farmhouse) so defenders can answer, then the tick settles it: horseshoes
 * times each animal's strength, a little luck, and ties to the defender.
 */
final class Battles
{
    public const MARCH_MINUTES = 60;

    public const FARMHOUSE_MARCH_MINUTES = 120;

    /**
     * @throws GameException
     */
    public function launch(Player $player, Region $target, int $horseshoes): Attack
    {
        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');
        $faction = $player->faction ?? throw new GameException('Join or found a faction to raid. Lone wolves can loot and burn instead.');

        $this->checkHorseshoes($horseshoes);

        if ($faction->members()->count() < (int) config('game.faction.members_to_raid')) {
            throw new GameException('A faction needs at least '.config('game.faction.members_to_raid').' members to raid.');
        }

        if ($target->isSafe()) {
            throw new GameException("{$target->name} is safe ground. Nobody can attack it.");
        }

        if ($target->faction_id === $faction->id) {
            throw new GameException("Your faction already holds {$target->name}.");
        }

        if ($target->settled_until !== null && $target->settled_until->isFuture()) {
            throw new GameException("{$target->name} just changed hands. It can be attacked again at ".Clock::local($target->settled_until)->format('H:i').'.');
        }

        if (Attack::query()->where('faction_id', $faction->id)->where('region_id', $target->id)->whereNull('resolved_at')->exists()) {
            throw new GameException("Your faction is already marching on {$target->name}. Add your horseshoes to that attack.");
        }

        $this->checkReach($faction, $target);
        $this->checkFrontLine($player, $target);
        $this->checkCurfew($season, $target);

        return DB::transaction(function () use ($player, $season, $faction, $target, $horseshoes) {
            $locked = Players::lock($player);
            Players::ensureFree($locked);
            Horseshoes::spend($locked, $horseshoes);

            $attack = Attack::create([
                'season_id' => $season->id,
                'faction_id' => $faction->id,
                'region_id' => $target->id,
                'defender_faction_id' => $target->faction_id,
                'player_id' => $locked->id,
                'lands_at' => $this->landing($target),
                'seed' => Dice::seed(),
            ]);

            BattleCommitment::create(['attack_id' => $attack->id, 'player_id' => $locked->id, 'side' => 'attack', 'horseshoes' => $horseshoes, 'boost' => Powers::commitment($locked)]);
            Missions::record($locked, 'battle');

            // Both sides hear of it under the bell, in time to add horseshoes.
            $lands = Clock::local($attack->lands_at)->format('H:i');
            $map = route('play', [], false);
            Notices::send(Notices::members($target->faction_id), 'raid', "⚔ {$faction->name} is marching on {$target->name}. The raid lands at {$lands}: add your horseshoes to defend it.", $map);
            Notices::send(Notices::members($faction->id)->reject(fn (int $id) => $id === $locked->id), 'raid', "⚔ {$locked->name} is leading a raid on {$target->name}. Add your horseshoes before {$lands}.", $map);

            return $attack;
        });
    }

    /**
     * Add horseshoes to an attack: attackers from the raiding faction,
     * defenders from the faction holding the region.
     *
     * @throws GameException
     */
    public function commit(Player $player, Attack $attack, int $horseshoes): BattleCommitment
    {
        $this->checkHorseshoes($horseshoes);

        if ($attack->resolved_at !== null || $attack->lands_at->isPast()) {
            throw new GameException('That battle is already over.');
        }

        $side = match (true) {
            $player->faction_id !== null && $player->faction_id === $attack->faction_id => 'attack',
            $player->faction_id !== null && $player->faction_id === $attack->region->faction_id => 'defence',
            default => throw new GameException("Only the attackers' and defenders' factions can join this battle."),
        };

        if (BattleCommitment::query()->where('attack_id', $attack->id)->where('player_id', $player->id)->exists()) {
            throw new GameException("You're already in this battle.");
        }

        return DB::transaction(function () use ($player, $attack, $side, $horseshoes) {
            $locked = Players::lock($player);
            Players::ensureFree($locked);
            Horseshoes::spend($locked, $horseshoes);

            $commitment = BattleCommitment::create(['attack_id' => $attack->id, 'player_id' => $locked->id, 'side' => $side, 'horseshoes' => $horseshoes, 'boost' => Powers::commitment($locked)]);
            Missions::record($locked, 'battle');

            return $commitment;
        });
    }

    /** Settle every attack that has landed. */
    public function resolveDue(Season $season): void
    {
        $season->attacks()
            ->whereNull('resolved_at')
            ->where('lands_at', '<=', CarbonImmutable::now())
            ->orderBy('lands_at')
            ->get()
            ->each(fn (Attack $attack) => $this->resolve($season, $attack));
    }

    public function resolve(Season $season, Attack $attack): void
    {
        DB::transaction(function () use ($season, $attack) {
            $attack = Attack::query()->lockForUpdate()->findOrFail($attack->id);

            if ($attack->resolved_at !== null) {
                return;
            }

            $region = Region::query()->lockForUpdate()->findOrFail($attack->region_id);

            if ($region->faction_id === $attack->faction_id) {
                $attack->forceFill(['resolved_at' => CarbonImmutable::now(), 'winner' => 'attack'])->save();

                return;
            }

            $commitments = $attack->commitments()->with('player')->get();
            $attackerRegions = Region::query()->where('faction_id', $attack->faction_id)->count();
            $underdog = match (true) {
                $attackerRegions <= 2 => 1.25,
                $attackerRegions >= 8 => 0.85,
                default => 1.0,
            };
            $pigLed = $attack->launcher?->species === Species::Pig ? 1.1 : 1.0;

            $attackStrength = $commitments->where('side', 'attack')
                ->filter(fn (BattleCommitment $c) => $c->player->faction_id === $attack->faction_id)
                ->sum(fn (BattleCommitment $c) => $c->horseshoes * $c->boost * $c->player->species->attack()) * $underdog * $pigLed;

            $defenceStrength = $region->faction_id === null ? 0.0 : $commitments->where('side', 'defence')
                ->filter(fn (BattleCommitment $c) => $c->player->faction_id === $region->faction_id)
                ->sum(fn (BattleCommitment $c) => $c->horseshoes * $c->boost * $c->player->species->defence()) * $region->ground() * 1.2;

            // A Den or Watchtower makes its region harder to take; a Drill Yard next door hits harder.
            $defenceStrength *= Bases::defence($region);
            $attackStrength *= Bases::attack($attack->faction_id, $region);

            // A War Banner and a Thorn Hedge, from the Workshop's recipes.
            $attackStrength *= Powers::attack($attack->faction_id);
            $defenceStrength *= Powers::defence($region);

            $attackStrength *= Dice::roll($attack->seed, 'attack', 0.9, 1.1);
            $defenceStrength *= Dice::roll($attack->seed, 'defence', 0.9, 1.1);
            $won = $attackStrength > $defenceStrength;

            $attack->forceFill([
                'resolved_at' => CarbonImmutable::now(),
                'winner' => $won ? 'attack' : 'defence',
                'attack_strength' => round($attackStrength, 2),
                'defence_strength' => round($defenceStrength, 2),
                'defender_faction_id' => $region->faction_id,
            ])->save();

            $defenderId = $region->faction_id;

            if ($won) {
                $this->take($season, $region, $attack->faction_id);
            }

            $this->announce($region->name, $attack->faction_id, $defenderId, $won);

            foreach ($commitments as $commitment) {
                $onWinningSide = ($commitment->side === 'attack') === $won;

                if ($onWinningSide) {
                    Rosettes::award(
                        $commitment->player,
                        $won ? 10 : 8,
                        $won ? "Won the raid on {$region->name}" : "Held {$region->name}",
                        "battle:{$attack->id}:{$commitment->player_id}",
                        $season,
                    );
                }
            }
        });
    }

    /** Tell both factions how a raid went. */
    private function announce(string $region, int $attackerId, ?int $defenderId, bool $won): void
    {
        $attackers = Faction::query()->whereKey($attackerId)->value('name');
        $defenders = $defenderId !== null ? Faction::query()->whereKey($defenderId)->value('name') : null;
        $map = route('play', [], false);

        if ($won) {
            Notices::send(Notices::members($attackerId), 'battle', "🏆 Your faction took {$region}".($defenders ? " from {$defenders}" : '').'.', $map);
            Notices::send(Notices::members($defenderId), 'battle', "{$region} fell to {$attackers}.", $map);

            return;
        }

        Notices::send(Notices::members($attackerId), 'battle', "Your raid on {$region} failed: {$defenders} held it.", $map);
        Notices::send(Notices::members($defenderId), 'battle', "🛡 Your faction held {$region} against {$attackers}.", $map);
    }

    /** A faction takes a region, and with the Farmhouse, the valley. */
    public function take(Season $season, Region $region, ?int $factionId): void
    {
        app(Bases::class)->fall($region, $factionId);

        $region->forceFill([
            'faction_id' => $factionId,
            'held_since' => $factionId ? CarbonImmutable::now() : null,
            'settled_until' => CarbonImmutable::now()->addHour(),
        ])->save();

        if ($region->isFarmhouse()) {
            $season->forceFill([
                'house_since' => $factionId ? CarbonImmutable::now() : null,
                'bonus_law_changes' => $season->bonus_law_changes + 3,
            ])->save();
        }
    }

    /**
     * Big empires cost more: the first four regions are free, the fifth costs
     * 2 Corn a tick, the sixth 4, the seventh 8. A faction that can't pay
     * loses its newest region.
     */
    public function upkeep(Season $season): void
    {
        Faction::query()->where('valley_id', $season->valley_id)->get()->each(function (Faction $faction) {
            DB::transaction(function () use ($faction) {
                $faction = Faction::query()->lockForUpdate()->findOrFail($faction->id);

                while (($count = $faction->regions()->count()) > 4) {
                    $cost = 2 ** ($count - 3) - 2;

                    if ($faction->treasury >= $cost) {
                        $faction->decrement('treasury', $cost);

                        return;
                    }

                    $faction->regions()->where('key', '!=', 'farmhouse')->latest('held_since')->first()
                        ?->forceFill(['faction_id' => null, 'held_since' => null])->save();
                }
            });
        });
    }

    /**
     * @throws GameException
     */
    private function checkReach(Faction $faction, Region $target): void
    {
        $held = $target->neighbours()->where('faction_id', $faction->id)->count();

        if ($target->isFarmhouse()) {
            if ($held < 2) {
                throw new GameException('To attack the Farmhouse your faction must hold 2 of the 4 regions around it.');
            }

            return;
        }

        $fromPaddock = $target->neighbours()->where('key', 'paddock')->exists();

        if ($held === 0 && ! $fromPaddock) {
            throw new GameException("You can only attack a region next to one your faction holds. Factions without land start from the Paddock's neighbours.");
        }
    }

    /**
     * Raids start from the front line: the animal launching one must stand
     * in a region next to the target.
     *
     * @throws GameException
     */
    private function checkFrontLine(Player $player, Region $target): void
    {
        if (! $target->neighbours()->whereKey($player->location()->id)->exists()) {
            throw new GameException("Walk to a region next to {$target->name} first: raids start from the front line.");
        }
    }

    /**
     * @throws GameException
     */
    private function checkCurfew(Season $season, Region $target): void
    {
        $curfew = Wall::law($season, LawTemplate::Curfew);
        $house = $season->valley->house();

        if ($curfew === null || $house === null || $target->faction_id !== $house->id) {
            return;
        }

        if (Clock::hourIn(CarbonImmutable::now(), 22, (int) $curfew->value)) {
            throw new GameException('The curfew is on: no raids on House land right now.');
        }
    }

    /** No battles settle in the quiet hours, 01:00 to 06:00. */
    private function landing(Region $target): CarbonImmutable
    {
        $lands = CarbonImmutable::now()->addMinutes($target->isFarmhouse() ? self::FARMHOUSE_MARCH_MINUTES : self::MARCH_MINUTES);

        if (Clock::hourIn($lands, 1, 5)) {
            $lands = Clock::local($lands)->setTime(6, 0)->setTimezone(config('app.timezone'));
        }

        return $lands;
    }

    /**
     * @throws GameException
     */
    private function checkHorseshoes(int $horseshoes): void
    {
        if ($horseshoes < 1 || $horseshoes > 3) {
            throw new GameException('Put in 1 to 3 horseshoes.');
        }
    }
}
