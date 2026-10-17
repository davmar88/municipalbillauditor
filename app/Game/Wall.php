<?php

namespace App\Game;

use App\Enums\FactionRole;
use App\Enums\Species;
use App\Models\Expose;
use App\Models\Faction;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Player;
use App\Models\Season;
use App\Models\UnrestEvent;
use App\Models\WallCopy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Barn Wall: the House's five laws, the unrest they cause, and the
 * weekly secret edit players can catch.
 */
final class Wall
{
    public const SLOTS = 5;

    public const CHANGES_PER_DAY = 3;

    public const EXPOSE_WINDOW_HOURS = 72;

    public const GOAT_HINT_HOURS = 6;

    public const UPRISING_AT = 60;

    public const RISES_ALONE_AT = 90;

    /** @return Collection<int, Law> */
    public static function laws(Season $season): Collection
    {
        return $season->laws()->orderBy('slot')->get();
    }

    public static function law(Season $season, LawTemplate $template): ?Law
    {
        return $season->laws()->where('template', $template)->first();
    }

    public static function unrest(Season $season): int
    {
        $laws = (int) self::laws($season)->sum(fn (Law $law) => $law->unrest());
        $keepsEverything = $season->valley->house() !== null && self::law($season, LawTemplate::Rations) === null ? 15 : 0;
        $events = (int) $season->unrestEvents()->where('expires_at', '>', CarbonImmutable::now())->sum('amount');

        return max(0, min(100, $laws + $keepsEverything + $events));
    }

    /**
     * Paint, change or clear a law. Only the House's leader and council, at
     * most three public changes a day (plus any bonus changes).
     *
     * @throws GameException
     */
    public function setLaw(Player $player, int $slot, ?LawTemplate $template, ?int $value = null, ?string $target = null): Law|null
    {
        $season = $this->seasonFor($player);
        $this->ensureHouseDecider($player, $season);

        if ($slot < 1 || $slot > self::SLOTS) {
            throw new GameException('The Wall has five places for laws.');
        }

        if ($this->changesToday($season) >= self::CHANGES_PER_DAY + $season->bonus_law_changes) {
            throw new GameException('The House has used its law changes for today. More come tomorrow.');
        }

        return DB::transaction(function () use ($player, $season, $slot, $template, $value, $target) {
            $existing = $season->laws()->where('slot', $slot)->first();

            if ($template === null) {
                if ($existing === null) {
                    throw new GameException('That place on the Wall is already empty.');
                }

                $existing->delete();
                $this->record($season, $player, $slot, null, null, null, 'This place on the Wall was painted over.', false);
                $this->useChange($season);

                return null;
            }

            if ($season->laws()->where('template', $template)->where('slot', '!=', $slot)->exists()) {
                throw new GameException("{$template->label()} is already on the Wall. Change that law instead.");
            }

            [$value, $target] = $this->validate($player, $template, $value, $target);

            $law = $existing ?? new Law(['season_id' => $season->id, 'slot' => $slot]);
            $law->fill([
                'template' => $template,
                'value' => $value,
                'target' => $target,
                'expires_at' => $template === LawTemplate::TradeBan ? CarbonImmutable::now()->addHours(72) : null,
            ])->save();

            $this->record($season, $player, $slot, $template, $value, $target, $law->wording(), false);
            $this->useChange($season);

            return $law;
        });
    }

    /**
     * Once a week, between midnight and 04:00, the House leader may change
     * one number in one law without telling anyone.
     *
     * @throws GameException
     */
    public function secretEdit(Player $player, int $slot, int $value): Law
    {
        $season = $this->seasonFor($player);
        $house = $season->valley->house();

        if ($house === null || $player->faction_id !== $house->id || $player->faction_role !== FactionRole::Leader) {
            throw new GameException('Only the leader of the House can make a secret edit.');
        }

        if (! Clock::hourIn(CarbonImmutable::now(), 0, 4)) {
            throw new GameException('Secret edits can only be made between midnight and 04:00.');
        }

        if ($season->last_secret_edit_at !== null && $season->last_secret_edit_at->gt(CarbonImmutable::now()->subDays(7))) {
            throw new GameException('The House has made its secret edit this week.');
        }

        $law = $season->laws()->where('slot', $slot)->first();

        if ($law === null || $law->template->range() === null) {
            throw new GameException('A secret edit changes the number in a law, and that place has none.');
        }

        [$min, $max] = $law->template->range();

        if ($value < $min || $value > $max || $value === $law->value) {
            throw new GameException("Pick a different number between {$min} and {$max}.");
        }

        return DB::transaction(function () use ($season, $player, $law, $value) {
            $law->update(['value' => $value]);
            $this->record($season, $player, $law->slot, $law->template, $value, $law->target, $law->wording(), true);
            $season->forceFill(['last_secret_edit_at' => CarbonImmutable::now()])->save();

            return $law;
        });
    }

    /**
     * Save a dated copy of the Wall, once a day.
     *
     * @throws GameException
     */
    public function remember(Player $player): WallCopy
    {
        $season = $this->seasonFor($player);

        if (WallCopy::query()->where('player_id', $player->id)->where('created_at', '>=', Clock::startOfToday())->exists()) {
            throw new GameException('You have already saved a copy of the Wall today.');
        }

        return WallCopy::create([
            'player_id' => $player->id,
            'season_id' => $season->id,
            'laws' => $this->snapshot($season),
        ]);
    }

    public static function latestCopy(Player $player, Season $season): ?WallCopy
    {
        return WallCopy::query()->where('player_id', $player->id)->where('season_id', $season->id)->latest('id')->first();
    }

    /**
     * Claim that a law was secretly changed since the player's saved copy.
     *
     * @return array{correct: bool, rank: int|null, rosettes: int}
     *
     * @throws GameException
     */
    public function expose(Player $player, int $slot): array
    {
        $season = $this->seasonFor($player);
        $copy = self::latestCopy($player, $season);

        if ($copy === null) {
            throw new GameException('You need a saved copy of the Wall to prove a change. Tap "Remember the Wall" each day.');
        }

        if (Expose::query()->where('player_id', $player->id)->where('slot', $slot)->where('created_at', '>=', Clock::startOfToday())->exists()) {
            throw new GameException('You have already made a claim about that law today.');
        }

        return DB::transaction(function () use ($player, $season, $copy, $slot) {
            $version = $season->lawVersions()
                ->where('slot', $slot)
                ->where('secret', true)
                ->whereNull('settled_at')
                ->where('created_at', '>', $copy->created_at)
                ->latest('id')
                ->first();

            $current = $season->laws()->where('slot', $slot)->first();
            $saved = collect($copy->laws)->firstWhere('slot', $slot);
            $differs = $current !== null && $saved !== null && ((int) $saved['value'] !== (int) $current->value);

            if ($version === null || ! $differs) {
                $player = Players::lock($player);
                $penalty = min(2, Horseshoes::current($player));

                if ($penalty > 0) {
                    Horseshoes::spend($player, $penalty);
                }

                Expose::create(['player_id' => $player->id, 'slot' => $slot, 'correct' => false]);

                return ['correct' => false, 'rank' => null, 'rosettes' => 0];
            }

            $rank = Expose::query()->where('law_version_id', $version->id)->where('correct', true)->count() + 1;
            Expose::create(['player_id' => $player->id, 'law_version_id' => $version->id, 'slot' => $slot, 'correct' => true, 'rank' => $rank]);

            if ($rank === 1) {
                $version->forceFill(['exposed_at' => CarbonImmutable::now()])->save();
                $this->stir($season, 15, 'A secret edit was exposed', 72);
            }

            $rosettes = [1 => 50, 2 => 30, 3 => 20][$rank] ?? 0;
            Rosettes::award($player, $rosettes, 'Exposed a secret edit', "expose:{$version->id}:{$player->id}", $season);

            return ['correct' => true, 'rank' => $rank, 'rosettes' => $rosettes];
        });
    }

    /**
     * A feast paid from the treasury calms the valley for two days. Once
     * every three days.
     *
     * @throws GameException
     */
    public function festival(Player $player): void
    {
        $season = $this->seasonFor($player);
        $this->ensureHouseDecider($player, $season);

        if ($season->last_festival_at !== null && $season->last_festival_at->gt(CarbonImmutable::now()->subDays(3))) {
            throw new GameException('The House can hold a festival once every three days.');
        }

        DB::transaction(function () use ($player, $season) {
            $faction = Faction::query()->lockForUpdate()->findOrFail($player->faction_id);

            if ($faction->treasury < 500) {
                throw new GameException("A festival costs 500 Corn from the treasury. It holds {$faction->treasury}.");
            }

            $faction->decrement('treasury', 500);
            $season->forceFill(['last_festival_at' => CarbonImmutable::now()])->save();
            $this->stir($season, -10, 'The House held a festival', 48);
        });
    }

    /**
     * Sheep chant their faction's motto once a day: it calms the valley
     * under their own House and stirs it up under a rival one.
     *
     * @throws GameException
     */
    public function chant(Player $player): int
    {
        $season = $this->seasonFor($player);
        $house = $season->valley->house();

        if ($player->species !== Species::Sheep) {
            throw new GameException('Only sheep can chant.');
        }

        if ($player->faction === null || blank($player->faction->motto)) {
            throw new GameException('You need a faction with a motto to chant.');
        }

        if ($house === null) {
            throw new GameException('There is no House to chant for or against yet.');
        }

        $reason = "chant:{$player->id}";

        if ($season->unrestEvents()->where('reason', 'like', $reason.'%')->where('created_at', '>=', Clock::startOfToday())->exists()) {
            throw new GameException('You have already chanted today.');
        }

        $amount = $house->id === $player->faction_id ? -1 : 1;
        $direction = $amount < 0 ? 'calm' : 'stir';
        $todays = $season->unrestEvents()->where('reason', 'like', "chant:%:{$direction}")->where('created_at', '>=', Clock::startOfToday())->count();

        if ($todays >= 10) {
            throw new GameException('The valley has heard enough chanting today. Try again tomorrow.');
        }

        DB::transaction(function () use ($player, $season, $amount, $reason, $direction) {
            Horseshoes::spend(Players::lock($player), 1);
            UnrestEvent::create(['season_id' => $season->id, 'amount' => $amount, 'reason' => "{$reason}:{$direction}", 'expires_at' => CarbonImmutable::now()->addDay()]);
        });

        return $amount;
    }

    /** Secret edits nobody caught in 72 hours settle in, and the House earns a bonus change. */
    public function settleSecretEdits(Season $season): void
    {
        $season->lawVersions()
            ->where('secret', true)
            ->whereNull('exposed_at')
            ->whereNull('settled_at')
            ->where('created_at', '<=', CarbonImmutable::now()->subHours(self::EXPOSE_WINDOW_HOURS))
            ->get()
            ->each(function (LawVersion $version) use ($season) {
                $version->forceFill(['settled_at' => CarbonImmutable::now()])->save();
                $season->increment('bonus_law_changes');
            });
    }

    /** Trade bans last 72 hours. */
    public function expireLaws(Season $season): void
    {
        $season->laws()->whereNotNull('expires_at')->where('expires_at', '<=', CarbonImmutable::now())->get()->each(function (Law $law) use ($season) {
            $law->delete();
            $this->record($season, null, $law->slot, null, null, null, "The {$law->template->label()} ran out.", false);
        });
    }

    /**
     * The public history: every announced change, plus secret edits once
     * exposed or settled.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function history(Season $season, int $limit = 30): Collection
    {
        return $season->lawVersions()
            ->with('player:id,name')
            ->where(fn ($query) => $query->where('secret', false)->orWhereNotNull('exposed_at')->orWhereNotNull('settled_at'))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (LawVersion $version) => [
                'id' => $version->id,
                'slot' => $version->slot,
                'wording' => $version->wording,
                'by' => $version->secret ? null : $version->player?->name,
                'at' => $version->created_at->toIso8601String(),
                'kind' => match (true) {
                    $version->secret && $version->exposed_at !== null => 'exposed',
                    $version->secret => 'settled',
                    default => 'public',
                },
            ]);
    }

    /**
     * Places on the Wall a goat can see were quietly changed (after six
     * hours), for the player's own view.
     *
     * @return array<int, int>
     */
    public static function goatHints(Player $player, Season $season): array
    {
        if ($player->species !== Species::Goat) {
            return [];
        }

        return $season->lawVersions()
            ->where('secret', true)
            ->whereNull('exposed_at')
            ->whereNull('settled_at')
            ->where('created_at', '<=', CarbonImmutable::now()->subHours(self::GOAT_HINT_HOURS))
            ->pluck('slot')
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, array{slot: int, template: string, value: int|null, target: string|null, wording: string}> */
    public function snapshot(Season $season): array
    {
        return self::laws($season)->map(fn (Law $law) => [
            'slot' => $law->slot,
            'template' => $law->template->value,
            'value' => $law->value,
            'target' => $law->target,
            'wording' => $law->wording(),
        ])->values()->all();
    }

    public function stir(Season $season, int $amount, string $reason, int $hours): void
    {
        UnrestEvent::create([
            'season_id' => $season->id,
            'amount' => $amount,
            'reason' => $reason,
            'expires_at' => CarbonImmutable::now()->addHours($hours),
        ]);
    }

    private function seasonFor(Player $player): Season
    {
        return $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');
    }

    /**
     * @throws GameException
     */
    private function ensureHouseDecider(Player $player, Season $season): void
    {
        $house = $season->valley->house();

        if ($house === null || $player->faction_id !== $house->id || ! $player->decides()) {
            throw new GameException("Only the House's leader and council can change the laws.");
        }
    }

    private function changesToday(Season $season): int
    {
        return $season->lawVersions()
            ->where('secret', false)
            ->whereNotNull('player_id')
            ->where('created_at', '>=', Clock::startOfToday())
            ->count();
    }

    private function useChange(Season $season): void
    {
        if ($this->changesToday($season) > self::CHANGES_PER_DAY && $season->bonus_law_changes > 0) {
            $season->decrement('bonus_law_changes');
        }
    }

    /**
     * @return array{0: int|null, 1: string|null}
     *
     * @throws GameException
     */
    private function validate(Player $player, LawTemplate $template, ?int $value, ?string $target): array
    {
        if (($range = $template->range()) !== null) {
            [$min, $max] = $range;
            $value ??= $template->defaultValue();

            if ($value < $min || $value > $max) {
                throw new GameException("{$template->label()} must be between {$min} and {$max}{$template->unit()}.");
            }
        } else {
            $value = null;
        }

        $target = blank($target) ? null : $target;

        if ($template === LawTemplate::TradeBan) {
            $faction = Faction::query()->where('valley_id', $player->valley_id)->whereKey($target)->first();

            if ($faction === null || $faction->id === $player->faction_id) {
                throw new GameException('Name another faction to ban from the market.');
            }

            $target = (string) $faction->id;
        } elseif ($template === LawTemplate::Rations) {
            $target = $target !== null && Species::tryFrom($target) !== null ? $target : null;
        } else {
            $target = null;
        }

        return [$value, $target];
    }

    private function record(Season $season, ?Player $player, int $slot, ?LawTemplate $template, ?int $value, ?string $target, string $wording, bool $secret): void
    {
        LawVersion::create([
            'season_id' => $season->id,
            'slot' => $slot,
            'template' => $template?->value,
            'value' => $value,
            'target' => $target,
            'wording' => $wording,
            'player_id' => $player?->id,
            'secret' => $secret,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
