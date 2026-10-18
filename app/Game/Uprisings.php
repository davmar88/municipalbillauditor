<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Season;
use App\Models\Uprising;
use App\Models\UprisingPledge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The valley rising against the House. When unrest reaches 60 anyone can
 * call an uprising (at 90 it starts by itself). It needs enough pledges in
 * two hours; then rebels and loyalists put in horseshoes for six, and the
 * angrier the valley, the stronger the rebels.
 */
final class Uprisings
{
    public const GATHER_HOURS = 2;

    public const OPEN_HOURS = 6;

    public static function active(Season $season): ?Uprising
    {
        return $season->uprisings()->whereIn('status', ['gathering', 'open'])->latest('id')->first();
    }

    /**
     * @throws GameException
     */
    public function call(Player $player, int $horseshoes): Uprising
    {
        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');
        $house = $season->valley->house() ?? throw new GameException('There is no House to rise against yet.');

        if (Wall::unrest($season) < Wall::UPRISING_AT) {
            throw new GameException('An uprising can be called once unrest reaches '.Wall::UPRISING_AT.'.');
        }

        if ($player->faction_id === $house->id) {
            throw new GameException("You can't call an uprising against your own House.");
        }

        if (self::active($season) !== null) {
            throw new GameException('An uprising is already under way. Pledge to it instead.');
        }

        if ($season->uprisings()->where('status', 'failed')->where('resolved_at', '>', CarbonImmutable::now()->subHours(6))->exists()) {
            throw new GameException('The last uprising fizzled out. The valley needs a few hours before another.');
        }

        return DB::transaction(function () use ($player, $season, $horseshoes) {
            $uprising = $this->begin($season, $player);
            $this->pledge($player, $uprising, 'rebel', $horseshoes);

            return $uprising->fresh();
        });
    }

    /**
     * @throws GameException
     */
    public function pledge(Player $player, Uprising $uprising, string $side, int $horseshoes): UprisingPledge
    {
        if ($horseshoes < 1 || $horseshoes > 3) {
            throw new GameException('Put in 1 to 3 horseshoes.');
        }

        if (! in_array($side, ['rebel', 'loyal'], true)) {
            throw new GameException('Pick a side: rebel or loyal.');
        }

        return DB::transaction(function () use ($player, $uprising, $side, $horseshoes) {
            $uprising = Uprising::query()->lockForUpdate()->findOrFail($uprising->id);
            $house = Season::findOrFail($uprising->season_id)->valley->house();

            if (! $uprising->isActive()) {
                throw new GameException('That uprising is over.');
            }

            if ($uprising->status === 'gathering' && $side === 'loyal') {
                throw new GameException('Loyalists can answer once the uprising opens.');
            }

            if ($house !== null && $player->faction_id === $house->id && $side === 'rebel') {
                throw new GameException('Members of the House can only stand with the House.');
            }

            if ($uprising->pledges()->where('player_id', $player->id)->exists()) {
                throw new GameException("You've already pledged to this uprising.");
            }

            $locked = Players::lock($player);
            Players::ensureFree($locked);
            Horseshoes::spend($locked, $horseshoes);

            $pledge = UprisingPledge::create([
                'uprising_id' => $uprising->id,
                'player_id' => $locked->id,
                'faction_id' => $locked->faction_id,
                'side' => $side,
                'horseshoes' => $horseshoes,
            ]);

            if ($uprising->status === 'gathering' && $uprising->pledges()->where('side', 'rebel')->count() >= $uprising->pledges_needed) {
                $uprising->forceFill(['status' => 'open', 'closes_at' => CarbonImmutable::now()->addHours(self::OPEN_HOURS)])->save();
            }

            return $pledge;
        });
    }

    /** At 90 the valley rises without anyone calling it. */
    public function riseIfFurious(Season $season): void
    {
        if ($season->valley->house() === null || self::active($season) !== null || Wall::unrest($season) < Wall::RISES_ALONE_AT) {
            return;
        }

        $uprising = $this->begin($season, null);
        $uprising->forceFill(['status' => 'open', 'closes_at' => CarbonImmutable::now()->addHours(self::OPEN_HOURS)])->save();
    }

    public function resolveDue(Season $season, Battles $battles, Wall $wall): void
    {
        $uprising = self::active($season);

        if ($uprising === null) {
            return;
        }

        if ($uprising->status === 'gathering' && $uprising->gathering_until->isPast()) {
            $uprising->forceFill(['status' => 'failed', 'resolved_at' => CarbonImmutable::now()])->save();

            return;
        }

        if ($uprising->status !== 'open' || $uprising->closes_at->isFuture()) {
            return;
        }

        DB::transaction(function () use ($season, $uprising, $battles, $wall) {
            $pledges = $uprising->pledges()->with('player')->get();
            $anger = 1 + max(0, Wall::unrest($season) - 50) / 100;

            $rebels = $pledges->where('side', 'rebel')->sum(fn (UprisingPledge $p) => $p->horseshoes * $p->player->species->attack()) * $anger;
            $loyal = $pledges->where('side', 'loyal')->sum(fn (UprisingPledge $p) => $p->horseshoes * $p->player->species->defence()) * 1.2;
            $rebels *= Dice::roll($uprising->seed, 'rebels', 0.9, 1.1);
            $loyal *= Dice::roll($uprising->seed, 'loyal', 0.9, 1.1);
            $won = $rebels > $loyal;

            $winner = $won ? $pledges->where('side', 'rebel')->whereNotNull('faction_id')
                ->groupBy('faction_id')
                ->map(fn ($group) => $group->sum('horseshoes'))
                ->sortDesc()
                ->keys()
                ->first() : null;

            $uprising->forceFill([
                'status' => $won ? 'won' : 'lost',
                'resolved_at' => CarbonImmutable::now(),
                'rebel_strength' => round($rebels, 2),
                'loyal_strength' => round($loyal, 2),
                'winner_faction_id' => $winner,
            ])->save();

            if ($won) {
                $battles->take($season, $season->valley->region('farmhouse'), $winner);
                $wall->stir($season, -30, $winner !== null ? 'A new House promises change' : 'The House has fallen', 24);
            }

            foreach ($pledges as $pledge) {
                if (($pledge->side === 'rebel') === $won) {
                    Rosettes::award($pledge->player, $won ? 15 : 10, $won ? 'Rose up and won' : 'Stood by the House', "uprising:{$uprising->id}:{$pledge->player_id}", $season);
                }
            }
        });
    }

    private function begin(Season $season, ?Player $player): Uprising
    {
        $active = Players::activeCount($season->valley);

        return Uprising::create([
            'season_id' => $season->id,
            'player_id' => $player?->id,
            'status' => 'gathering',
            'pledges_needed' => min(15, max(3, (int) ceil($active * 0.1))),
            'gathering_until' => CarbonImmutable::now()->addHours(self::GATHER_HOURS),
            'seed' => Dice::seed(),
        ]);
    }
}
