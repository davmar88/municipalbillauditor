<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use App\Models\PlunderBand;
use App\Models\Season;
use App\Models\Tick as TickRecord;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every minute the valley moves on: battles, crimes and uprisings settle
 * when they are due, secret edits nobody caught settle in, upkeep and the
 * Night Watch are paid each half hour, rations go out each morning,
 * Ironfield raids on Sunday evening, and the season ends when its time is
 * up.
 *
 * Everything here is safe to run twice: each step checks what is due.
 */
final class Tick
{
    public function __construct(
        private readonly Wall $wall,
        private readonly Battles $battles,
        private readonly Uprisings $uprisings,
        private readonly Crimes $crimes,
        private readonly GateNight $gateNight,
        private readonly Factions $factions,
        private readonly Seasons $seasons,
        private readonly Bases $bases,
        private readonly Farms $farms,
        private readonly Research $research,
        private readonly Plunderers $plunderers,
    ) {}

    public function run(Valley $valley): void
    {
        $season = $valley->currentSeason();

        if ($season === null) {
            return;
        }

        // Whether anything on the map is due this minute: then every map refreshes once at the end.
        $now = CarbonImmutable::now();
        $due = Player::query()->where('valley_id', $valley->id)->whereNotNull('walk_arrives_at')->where('walk_arrives_at', '<=', $now)->exists()
            || $season->attacks()->whereNull('resolved_at')->where('lands_at', '<=', $now)->exists()
            || $season->crimes()->whereNull('resolved_at')->where('settles_at', '<=', $now)->exists()
            || PlunderBand::query()->where('season_id', $season->id)->whereNull('resolved_at')->where('lands_at', '<=', $now)->exists();
        $bands = PlunderBand::query()->where('season_id', $season->id)->count();

        Movement::settleArrived($valley->id);
        app(Homes::class)->buildArrivedCamps($valley->id);

        // Studies finish first, so what was learned counts this half hour.
        $this->research->finishDue($valley->id);
        $this->wall->settleSecretEdits($season);
        $this->wall->expireLaws($season);
        $this->battles->resolveDue($season);
        $this->bases->crown($valley);
        $this->crimes->resolveDue($season);
        $this->uprisings->resolveDue($season, $this->battles, $this->wall);
        $this->uprisings->riseIfFurious($season->fresh());
        $this->factions->promoteRecruits();
        $this->gateNight->resolveIfDue($season->fresh(), $this->wall);

        // Irongate's plunderers strike when due, and a new band rides out when it's time.
        $this->plunderers->resolveDue($season);
        $this->plunderers->muster($season);

        // Upkeep and the Night Watch are paid once per half hour.
        if ($this->claim($season)) {
            $this->battles->upkeep($season);
            $this->payNightWatch($season);
            $this->farms->payIncome($season);
            $this->bases->layEggs($season);
        }

        $this->handOutRations($season->fresh());
        $this->seasons->endIfDue($valley);

        // Buildings fall with land lost this tick: to raids, upkeep, Gate Night or a new season.
        $this->bases->clearRuins($valley);

        if ($due || PlunderBand::query()->where('season_id', $season->id)->count() !== $bands) {
            Live::touch($valley->id);
            Live::flush();
        }
    }

    /** Whether this half hour hasn't been run yet. */
    private function claim(Season $season): bool
    {
        $number = intdiv((int) max(0, $season->starts_at->diffInMinutes(CarbonImmutable::now())), 30);

        try {
            TickRecord::create(['season_id' => $season->id, 'number' => $number, 'ran_at' => CarbonImmutable::now()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** The Night Watch costs 50 Corn a tick; a House that can't pay loses it. */
    private function payNightWatch(Season $season): void
    {
        $law = Wall::law($season, LawTemplate::NightWatch);
        $house = $season->valley->house();

        if ($law === null || $house === null) {
            return;
        }

        if ($house->treasury >= 50) {
            Faction::query()->whereKey($house->id)->decrement('treasury', 50);

            return;
        }

        $law->delete();
    }

    /**
     * Each morning from 06:00 the House hands back its share of the grain
     * tax, split among everyone who visited in the last two days. The
     * species it favours get a fifth more.
     */
    private function handOutRations(Season $season): void
    {
        $now = Clock::now();
        $today = $now->toDateString();

        if ($now->hour < 6 || $season->last_rations_on?->toDateString() === $today) {
            return;
        }

        DB::transaction(function () use ($season, $today) {
            $season = Season::query()->lockForUpdate()->findOrFail($season->id);

            if ($season->last_rations_on?->toDateString() === $today) {
                return;
            }

            $law = Wall::law($season, LawTemplate::Rations);
            $house = $season->valley->house();
            $pot = ($law !== null && $house !== null) ? min($house->treasury, (int) floor($season->tax_collected * $law->value / 100)) : 0;

            $season->forceFill(['last_rations_on' => $today, 'tax_collected' => 0])->save();

            if ($pot <= 0) {
                return;
            }

            $players = Player::query()
                ->where('valley_id', $season->valley_id)
                ->where('last_visit_on', '>=', Clock::now()->subDays(2)->toDateString())
                ->get();

            $weight = fn (Player $player) => $player->species->value === $law->target ? 1.2 : 1.0;
            $shares = $players->sum($weight);

            if ($shares <= 0) {
                return;
            }

            $paid = 0;

            foreach ($players as $player) {
                $share = (int) floor($pot * $weight($player) / $shares + 1e-9);

                if ($share > 0) {
                    $player->increment('corn', $share);
                    $paid += $share;
                }
            }

            Faction::query()->whereKey($house->id)->decrement('treasury', $paid);
        });
    }
}
