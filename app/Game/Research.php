<?php

namespace App\Game;

use App\Enums\Study;
use App\Models\Faction;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Faction studies. A faction's leader and council start one study at a
 * time, paid from the treasury and the store, and once it is done the whole
 * faction is stronger for good. Study points are a faction's levels added
 * up; Ironfield sends more men to Gate Night as the strongest faction
 * learns more.
 */
final class Research
{
    public function __construct(private readonly Bases $bases) {}

    /** A faction's level in a study, counting one that has just finished. */
    public static function level(?Faction $faction, Study $study): int
    {
        if ($faction === null) {
            return 0;
        }

        $level = (int) ($faction->studies[$study->value] ?? 0);

        return $faction->studying === $study->value && self::done($faction) ? $level + 1 : $level;
    }

    /** A faction's study levels, added up. */
    public static function points(Faction $faction): int
    {
        return array_sum(array_map(fn (Study $study) => self::level($faction, $study), Study::cases()));
    }

    /** The most study points any faction in the valley has. */
    public static function topPoints(int $valleyId): int
    {
        return (int) Faction::query()->where('valley_id', $valleyId)->get()->max(fn (Faction $faction) => self::points($faction));
    }

    /** How much harder a faction's raids hit. */
    public static function attack(?int $factionId): float
    {
        return 1 + Study::Attack->bonus(self::levelOf($factionId, Study::Attack));
    }

    /** How much harder a faction's land, and its members at the gate, hold. */
    public static function defence(?int $factionId): float
    {
        return 1 + Study::Defence->bonus(self::levelOf($factionId, Study::Defence));
    }

    /** How much more an animal gathers, from its faction's Farming. */
    public static function gathering(Player $player): float
    {
        return 1 + Study::Farming->bonus(self::levelOf($player->faction_id, Study::Farming));
    }

    /** How long a faction's raid takes to land, after Marching. */
    public static function march(?int $factionId, int $minutes): int
    {
        return (int) round($minutes * (1 - Study::Marching->bonus(self::levelOf($factionId, Study::Marching))));
    }

    /** How long a level takes a faction to study, after Science. */
    public static function minutes(Faction $faction, Study $study, int $level): int
    {
        return (int) round($study->minutes($level) * (1 - Study::Science->bonus(self::level($faction, Study::Science))));
    }

    /**
     * Start a faction's next level of a study.
     *
     * @throws GameException
     */
    public function start(Player $player, Study $study): Faction
    {
        return DB::transaction(function () use ($player, $study) {
            $player = Players::lock($player);
            Players::ensureFree($player);

            if ($player->faction_id === null) {
                throw new GameException('Join a faction to study.');
            }

            if (! $player->decides()) {
                throw new GameException("Only your faction's leader and council start studies. Give to the store instead.");
            }

            $faction = Faction::query()->lockForUpdate()->findOrFail($player->faction_id);
            $this->finish($faction);

            if ($faction->studying !== null) {
                $busy = Study::from($faction->studying);

                throw new GameException("Your faction is studying {$busy->label()} until ".Clock::local($faction->studying_until)->format('H:i').'. It studies one thing at a time.');
            }

            $level = self::level($faction, $study) + 1;

            if ($level > Study::MAX_LEVEL) {
                throw new GameException("Your faction has learned all there is of {$study->label()}.");
            }

            $this->bases->pay($faction, $study->cost($level));
            $faction->forceFill([
                'studying' => $study->value,
                'studying_until' => CarbonImmutable::now()->addMinutes(self::minutes($faction, $study, $level)),
            ])->save();

            $done = Clock::local($faction->studying_until)->format('H:i');
            Notices::send(Notices::members($faction->id)->reject(fn (int $id) => $id === $player->id), 'study', "📚 {$player->name} started your faction studying {$study->label()} {$level}. It's done at {$done}.", route('play.faction', [], false));

            return $faction;
        });
    }

    /** Write down every study in the valley that is done, and tell each faction. */
    public function finishDue(int $valleyId): void
    {
        Faction::query()
            ->where('valley_id', $valleyId)
            ->whereNotNull('studying')
            ->where('studying_until', '<=', CarbonImmutable::now())
            ->pluck('id')
            ->each(fn (int $id) => DB::transaction(fn () => $this->finish(Faction::query()->lockForUpdate()->findOrFail($id))));
    }

    /**
     * Everything the Faction page shows about a faction's studies.
     *
     * @return array<string, mixed>
     */
    public static function page(Faction $faction, Player $player): array
    {
        $studying = $faction->studying !== null && ! self::done($faction) ? Study::from($faction->studying) : null;

        return [
            'points' => self::points($faction),
            'canStudy' => $player->decides(),
            'studying' => $studying === null ? null : [
                'value' => $studying->value,
                'label' => $studying->label(),
                'level' => self::level($faction, $studying) + 1,
                'until' => $faction->studying_until->toIso8601String(),
            ],
            'studies' => array_map(function (Study $study) use ($faction) {
                $level = self::level($faction, $study);

                return [
                    'value' => $study->value,
                    'label' => $study->label(),
                    'level' => $level,
                    'max' => Study::MAX_LEVEL,
                    'does' => $level > 0 ? $study->does($level) : null,
                    'next' => $level >= Study::MAX_LEVEL ? null : [
                        'level' => $level + 1,
                        'does' => $study->does($level + 1),
                        ...$study->cost($level + 1),
                        'minutes' => self::minutes($faction, $study, $level + 1),
                    ],
                ];
            }, Study::cases()),
        ];
    }

    private static function levelOf(?int $factionId, Study $study): int
    {
        return $factionId === null ? 0 : self::level(Faction::query()->find($factionId), $study);
    }

    private static function done(Faction $faction): bool
    {
        return $faction->studying_until !== null && ! $faction->studying_until->isFuture();
    }

    /** Write down a finished study's new level and tell the faction. */
    private function finish(Faction $faction): void
    {
        if ($faction->studying === null || ! self::done($faction)) {
            return;
        }

        $study = Study::from($faction->studying);
        $level = self::level($faction, $study);

        $faction->forceFill([
            'studies' => [...($faction->studies ?? []), $study->value => $level],
            'studying' => null,
            'studying_until' => null,
        ])->save();

        Notices::send(Notices::members($faction->id), 'study', "📚 Your faction finished studying {$study->label()} {$level}. {$study->does($level)}", route('play.faction', [], false));
    }
}
