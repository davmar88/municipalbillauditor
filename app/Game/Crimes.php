<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Home;
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

    /** What a plunder carries off: crop gathered, and Corn from the holders' treasury. */
    public const PLUNDER_CROP = 8;

    public const PLUNDER_CORN = 20;

    /** A plunderer lies low this long, and a plundered section is watched this long. */
    public const PLUNDER_REST_MINUTES = 60;

    public const PLUNDER_WATCH_MINUTES = 120;

    /** How long an animal that left a faction waits before its first crime. */
    public const LONE_WOLF_HOURS = 24;

    /**
     * @throws GameException
     */
    public function commit(Player $player, CrimeKind $kind, ?Faction $faction = null, ?Player $target = null): Crime
    {
        $region = null;

        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');
        $level = (int) config('game.levels.crime');

        // Faction members commit crimes too, against their own faction if they dare: its rules decide what that costs them.
        if ($player->level() < $level) {
            throw new GameException("Crime opens up at level {$level}.");
        }

        // Leaving a faction to steal and coming straight back doesn't work: a day as a lone wolf first.
        if ($player->isLoneWolf() && $player->left_faction_at !== null && $player->left_faction_at->gt(now()->subHours(self::LONE_WOLF_HOURS))) {
            throw new GameException('You only just left your faction. Lie low as a lone wolf until '.Clock::local($player->left_faction_at->copy()->addHours(self::LONE_WOLF_HOURS))->format('j M \a\t H:i').' before your first crime.');
        }

        // Plunder runs on its own clock; looting and burning are once a day.
        if ($kind !== CrimeKind::Plunder && Crime::query()->where('player_id', $player->id)->where('kind', '!=', CrimeKind::Plunder)->where('created_at', '>=', Clock::startOfToday())->exists()) {
            throw new GameException('You have already tried a crime today. Lie low until tomorrow.');
        }

        if ($kind === CrimeKind::Plunder) {
            $region = $player->location();

            if (! $region->isFarm() || $region->wild) {
                throw new GameException('Plunder happens on the farms beyond the fences. Walk onto a farm section a faction holds.');
            }

            if ($region->faction_id === null) {
                throw new GameException("{$region->name} belongs to nobody yet. Just gather there.");
            }

            $last = Crime::query()->where('player_id', $player->id)->where('kind', CrimeKind::Plunder)->latest('id')->first();

            if ($last !== null && $last->created_at->gt(CarbonImmutable::now()->subMinutes(self::PLUNDER_REST_MINUTES))) {
                throw new GameException('You plundered not long ago. Lie low until '.Clock::local($last->created_at->addMinutes(self::PLUNDER_REST_MINUTES))->format('H:i').'.');
            }

            $watched = Crime::query()->where('region_id', $region->id)->where('kind', CrimeKind::Plunder)->latest('id')->first();

            if ($watched !== null && $watched->created_at->gt(CarbonImmutable::now()->subMinutes(self::PLUNDER_WATCH_MINUTES))) {
                throw new GameException("{$region->name} was plundered not long ago and its holders are watching. Try again after ".Clock::local($watched->created_at->addMinutes(self::PLUNDER_WATCH_MINUTES))->format('H:i').'.');
            }

            $faction = Faction::query()->find($region->faction_id);
        } elseif ($kind === CrimeKind::Loot) {
            if ($faction === null || $faction->valley_id !== $player->valley_id) {
                throw new GameException('Pick a faction to loot.');
            }

            if (Crime::query()->where('target_faction_id', $faction->id)->where('kind', '!=', CrimeKind::Plunder)->where('created_at', '>=', Clock::startOfToday())->exists()) {
                throw new GameException("{$faction->name} has already been targeted today.");
            }
        } else {
            if ($target === null || $target->valley_id !== $player->valley_id || ($target->faction_id === null && Homes::of($target) === null)) {
                throw new GameException('Pick a home to burn.');
            }

            if ($target->is($player)) {
                throw new GameException("That's your own home.");
            }

            if ($target->isShielded()) {
                throw new GameException("{$target->name} is new to the valley and protected for now.");
            }

            if (Powers::locked($target->id)) {
                throw new GameException("{$target->name}'s home is shut with an Iron Lock. Try another.");
            }

            if ($target->homeIsClosed() || ($target->home_burned_at !== null && $target->home_burned_at->gt(CarbonImmutable::now()->subDays(7)))) {
                throw new GameException("{$target->name}'s home burned recently. A home can burn at most once a week.");
            }
        }

        return DB::transaction(function () use ($player, $kind, $faction, $target, $season, $region) {
            $locked = Players::lock($player);
            Players::ensureFree($locked);

            if ($locked->isLoneWolf() && $locked->left_faction_at !== null && $locked->left_faction_at->gt(now()->subHours(self::LONE_WOLF_HOURS))) {
                throw new GameException('You only just left your faction. Lie low as a lone wolf for a day before your first crime.');
            }

            Horseshoes::spend($locked, $kind->horseshoes());
            Missions::record($locked, 'crime');

            // A crime against your own faction may break its rules.
            $victim = $kind === CrimeKind::Burn ? $target?->faction_id : $faction?->id;
            if ($locked->faction_id !== null && $victim === $locked->faction_id) {
                RaidRules::judgeCrime($locked, $kind, $kind === CrimeKind::Burn ? $target?->name : ($region?->name ?? $faction?->name));
            }

            return Crime::create([
                'season_id' => $season->id,
                'player_id' => $locked->id,
                'kind' => $kind,
                'target_faction_id' => $kind === CrimeKind::Burn ? $target?->faction_id : $faction?->id,
                'target_player_id' => $target?->id,
                'region_id' => $region?->id,
                'settles_at' => CarbonImmutable::now()->addMinutes(30),
                'seed' => Dice::seed(),
                'cloaked' => Powers::cloak($locked),
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
                    $share = Bases::lootShare($faction);
                    $loot = (int) floor($faction->treasury * $share);
                    $kept = $faction->materials ?? [];
                    $carried = $criminal->materials ?? [];

                    foreach ($kept as $name => $amount) {
                        $stolen = (int) floor($amount * $share);
                        $kept[$name] = $amount - $stolen;
                        $carried[$name] = (int) ($carried[$name] ?? 0) + $stolen;
                    }

                    $faction->forceFill(['treasury' => $faction->treasury - $loot, 'materials' => $kept])->save();
                    $criminal->forceFill(['corn' => $criminal->corn + $loot, 'materials' => $carried])->save();
                }
            } elseif ($crime->kind === CrimeKind::Plunder && $crime->region_id !== null) {
                // The plunderer gathers the section's crop, and takes Corn from its holders.
                $region = $crime->region;
                $materials = $criminal->materials;
                $materials[$region->produces] = (int) ($materials[$region->produces] ?? 0) + self::PLUNDER_CROP;
                $criminal->materials = $materials;
                $criminal->save();

                $faction = $crime->target_faction_id !== null ? Faction::query()->lockForUpdate()->find($crime->target_faction_id) : null;

                if ($faction !== null) {
                    $loot = min(self::PLUNDER_CORN, $faction->treasury);
                    $faction->decrement('treasury', $loot);
                    $criminal->increment('corn', $loot);
                }
            } elseif ($crime->kind === CrimeKind::Burn && $crime->target_player_id !== null) {
                Player::query()->whereKey($crime->target_player_id)->update([
                    'home_closed_until' => CarbonImmutable::now()->addDay(),
                    'home_burned_at' => CarbonImmutable::now(),
                ]);
                Homes::burned($crime->target_player_id);
            }

            $crime->forceFill(['resolved_at' => CarbonImmutable::now(), 'caught' => $caught, 'loot' => $loot])->save();
            $this->announce($crime, $criminal, $caught, $loot);
        });
    }

    /** Tell the criminal, and the victims, how a crime went. */
    private function announce(Crime $crime, Player $criminal, bool $caught, int $loot): void
    {
        $underworld = route('play.faction', [], false);
        $victims = $crime->kind === CrimeKind::Burn ? collect([$crime->target_player_id]) : Notices::members($crime->target_faction_id);
        $section = $crime->region?->name;

        if ($caught) {
            Notices::send($criminal->id, 'crime', '🚨 You were caught and sent to the pound until '.Clock::local($criminal->pound_until)->format('H:i').'.', $underworld);
            Notices::send($victims, 'crime', match ($crime->kind) {
                CrimeKind::Loot => "🚨 A lone wolf tried to loot your faction's treasury and was caught.",
                CrimeKind::Plunder => "🚨 A lone wolf tried to plunder {$section} and was caught.",
                CrimeKind::Burn => '🚨 Someone tried to burn down your home and was caught.',
            }, $underworld);

            return;
        }

        if ($crime->kind === CrimeKind::Plunder) {
            $crop = $crime->region?->produces;
            Notices::send($criminal->id, 'crime', '🌾 You plundered '.self::PLUNDER_CROP." {$crop} and {$loot} Corn from {$section}.", route('play', [], false));
            Notices::send($victims, 'crime', "🔥 A lone wolf plundered {$section}, taking {$loot} Corn from your treasury. Ask the House for a warrant.", route('play', [], false));

            return;
        }

        if ($crime->kind === CrimeKind::Loot) {
            Notices::send($criminal->id, 'crime', "💰 You got away with {$loot} Corn.", $underworld);
            Notices::send($victims, 'crime', "🔥 A lone wolf looted {$loot} Corn from your faction's treasury.", $underworld);

            return;
        }

        $target = Player::query()->whereKey($crime->target_player_id)->value('name');
        Notices::send($criminal->id, 'crime', "🔥 You burned down {$target}'s home.", $underworld);
        Notices::send($victims, 'crime', '🔥 Your home was burned down. Your shop is closed for a day, or rebuild it now for '.self::REBUILD_CORN.' Corn.', route('play.market', [], false));
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
        // A home built up into a Cottage or a Fort is harder to set alight unseen.
        $walls = $crime->kind === CrimeKind::Burn && $crime->target_player_id !== null
            ? (Home::query()->where('player_id', $crime->target_player_id)->value('level') ?? 0) * Homes::BURN_GUARD
            : 0.0;
        $chance = min(0.9, 0.3 + 0.05 * min(4, $dogs) + $watch + Powers::bell($crime->target_faction_id) + $walls);

        // A Night Cloak halves it.
        return $crime->cloaked ? $chance * Powers::CLOAK : $chance;
    }
}
