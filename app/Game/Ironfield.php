<?php

namespace App\Game;

use App\Models\IronfieldRun;
use App\Models\Player;
use App\Models\Region;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ironfield, the humans' mining country east of Irongate. Nobody holds it.
 * Each of its regions has a mission: slip past the men guarding it and
 * carry off what they dig. The deeper in, the more men stand guard and the
 * more there is to take. Lose, and they drag you to the pound.
 */
final class Ironfield
{
    /** How deep each region lies: 1 at the road out of Irongate, 4 at the far pits. */
    public const DEPTH = [
        'ironfield_road' => 1,
        'ironfield_carts' => 2,
        'ironfield_foundry' => 2,
        'ironfield_kilns' => 2,
        'ironfield_slag' => 3,
        'ironfield_pithead' => 3,
        'ironfield_lodge' => 3,
        'ironfield_shaft' => 4,
        'ironfield_pit' => 4,
        'ironfield_chains' => 4,
    ];

    /** The men guarding each depth. */
    public const MEN = [1 => 2, 2 => 4, 3 => 7, 4 => 11];

    /** The most horseshoes one mission takes. */
    public const MAX_HORSESHOES = 10;

    /** How long before the same region's mission can be tried again. */
    public const COOLDOWN_HOURS = 4;

    /** What a win carries off, by depth. */
    public const REWARD = [
        1 => ['corn' => 40, 'rosettes' => 5, 'materials' => ['iron' => 3]],
        2 => ['corn' => 90, 'rosettes' => 10, 'materials' => ['iron' => 6, 'copper' => 1]],
        3 => ['corn' => 160, 'rosettes' => 18, 'materials' => ['iron' => 10, 'copper' => 4, 'gold' => 1]],
        4 => ['corn' => 260, 'rosettes' => 30, 'materials' => ['iron' => 15, 'copper' => 6, 'gold' => 3]],
    ];

    /** Each region's mission: what you do, and who stands in your way. */
    public const MISSIONS = [
        'ironfield_road' => ['title' => 'Rob the ore wagons', 'guards' => 'two carters with whips'],
        'ironfield_carts' => ['title' => 'Tip the ore carts', 'guards' => 'the track gang and their foreman'],
        'ironfield_foundry' => ['title' => 'Raid the foundry stores', 'guards' => 'the foundry watch'],
        'ironfield_kilns' => ['title' => 'Steal from the kilns', 'guards' => 'the kiln men and their dogs'],
        'ironfield_slag' => ['title' => 'Sift the slag heaps', 'guards' => 'a patrol with lanterns'],
        'ironfield_pithead' => ['title' => 'Cut the pithead ropes', 'guards' => 'the winding crew and a guard post'],
        'ironfield_lodge' => ['title' => "Rob the overseer's strongbox", 'guards' => "the overseer's own guards"],
        'ironfield_shaft' => ['title' => 'Go down the Black Shaft', 'guards' => 'miners with picks and a sentry line'],
        'ironfield_pit' => ['title' => 'Take gold from the Deep Pit', 'guards' => 'the pit bosses and their hired guns'],
        'ironfield_chains' => ['title' => 'Free the chained beasts', 'guards' => "Ironfield's barracks, every man awake"],
    ];

    public static function depth(Region $region): ?int
    {
        return self::DEPTH[$region->key] ?? null;
    }

    /** The odds of getting away with it: horseshoes and fighting strength against the men. */
    public static function chance(Player $player, int $depth, int $horseshoes): float
    {
        $power = $horseshoes * $player->species->attack();

        return round(min(0.95, max(0.05, $power / ($power + self::MEN[$depth]))), 2);
    }

    /** When this player can next try a region's mission, or null if now. */
    public static function nextAt(Player $player, Region $region): ?CarbonImmutable
    {
        $last = IronfieldRun::query()->where('player_id', $player->id)->where('region_id', $region->id)->latest('id')->value('created_at');

        if ($last === null) {
            return null;
        }

        $next = CarbonImmutable::parse($last)->addHours(self::COOLDOWN_HOURS);

        return $next->isFuture() ? $next : null;
    }

    /**
     * Take on a region's mission with so many horseshoes.
     *
     * @return array{won: bool, chance: float, reward: array{corn: int, rosettes: int, materials: array<string, int>}|null, poundUntil: CarbonImmutable|null}
     *
     * @throws GameException
     */
    public function run(Player $player, Region $region, int $horseshoes): array
    {
        return DB::transaction(function () use ($player, $region, $horseshoes) {
            $player = Players::lock($player);
            Players::ensureFree($player);
            $depth = self::depth($region);

            if ($depth === null || $region->valley_id !== $player->valley_id) {
                throw new GameException('Missions like this are only out in Ironfield.');
            }

            Movement::ensureStandingIn($player, $region, 'take on its mission');

            if ($horseshoes < 1 || $horseshoes > self::MAX_HORSESHOES) {
                throw new GameException('Put 1 to '.self::MAX_HORSESHOES.' horseshoes into it.');
            }

            if (($next = self::nextAt($player, $region)) !== null) {
                throw new GameException("The men at {$region->name} are still on their guard. Try again after ".Clock::local($next)->format('H:i').'.');
            }

            Horseshoes::spend($player, $horseshoes);
            $chance = self::chance($player, $depth, $horseshoes);
            $won = Dice::roll(Dice::seed(), 'ironfield') < $chance;
            $season = $player->valley->currentSeason();

            IronfieldRun::query()->create([
                'player_id' => $player->id,
                'region_id' => $region->id,
                'depth' => $depth,
                'horseshoes' => $horseshoes,
                'won' => $won,
            ]);

            if ($won) {
                $reward = self::REWARD[$depth];
                $materials = $player->materials ?? [];

                foreach ($reward['materials'] as $material => $amount) {
                    $materials[$material] = (int) ($materials[$material] ?? 0) + $amount;
                }

                $player->forceFill(['corn' => $player->corn + $reward['corn'], 'materials' => $materials])->save();
                Rosettes::award($player, $reward['rosettes'], "Ironfield: {$region->name}", "ironfield:{$player->id}:{$region->id}:".now()->timestamp, $season);
                Missions::record($player, 'ironfield');

                return ['won' => true, 'chance' => $chance, 'reward' => $reward, 'poundUntil' => null];
            }

            // Caught: the men drag you back over the gate and into the pound, longer the deeper you went.
            $paddock = $player->valley->region('paddock');
            [$x, $y] = Geography::hub('paddock');
            // A faction Hospital patches its members up sooner.
            $until = CarbonImmutable::now()->addMinutes((int) round($depth * 60 * (1 - Bases::healing($player->faction))));
            $player->forceFill([
                'pound_until' => $until,
                'region_id' => $paddock?->id,
                'pos_x' => $x,
                'pos_y' => $y,
                'walk_to_x' => null,
                'walk_to_y' => null,
                'walk_started_at' => null,
                'walk_arrives_at' => null,
                'inside_home_at' => null,
            ])->save();

            return ['won' => false, 'chance' => $chance, 'reward' => null, 'poundUntil' => $until];
        });
    }
}
