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

    /** A burner whose fire was put out was seen at it: this much likelier to be caught. */
    public const DOUSE_SPOTTED = 0.25;

    public const REBUILD_CORN = 100;

    /** What a plunder carries off: crop gathered, and Corn from the holders' treasury. */
    public const PLUNDER_CROP = 8;

    public const PLUNDER_CORN = 20;

    /** A plunderer lies low this long, and a plundered section is watched this long. */
    public const PLUNDER_REST_MINUTES = 60;

    public const PLUNDER_WATCH_MINUTES = 120;

    /** A robber takes this share of what its victim carries: Corn and each material. */
    public const ROB_SHARE = 0.15;

    /** At most this much Corn in one robbery. */
    public const ROB_MOST_CORN = 250;

    /** How near a robber must stand to its victim, in paces. */
    public const ROB_REACH = 60;

    /** A robber lies low this long between robberies, and a victim is left alone this long after being robbed. */
    public const ROB_REST_MINUTES = 120;

    public const ROB_GUARD_HOURS = 6;

    /** A robbery is over quickly: it settles after this long. */
    public const ROB_MINUTES = 10;

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
        Players::ensureFree($player);

        // Faction members commit crimes too, against their own faction if they dare: its rules decide what that costs them.
        if ($player->level() < $level) {
            throw new GameException("Crime opens up at level {$level}.");
        }

        // Leaving a faction to steal and coming straight back doesn't work: a day as a lone wolf first.
        if ($player->isLoneWolf() && $player->left_faction_at !== null && $player->left_faction_at->gt(now()->subHours(self::LONE_WOLF_HOURS))) {
            throw new GameException('You only just left your faction. Lie low as a lone wolf until '.Clock::local($player->left_faction_at->copy()->addHours(self::LONE_WOLF_HOURS))->format('j M \a\t H:i').' before your first crime.');
        }

        // Plunder and robbery run on their own clocks; looting and burning are once a day.
        if (in_array($kind, [CrimeKind::Loot, CrimeKind::Burn], true) && self::triedToday($player)) {
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
        } elseif ($kind === CrimeKind::Rob) {
            $this->ensureCanRob($player, $target);
        } elseif ($kind === CrimeKind::Loot) {
            if ($faction === null || $faction->valley_id !== $player->valley_id) {
                throw new GameException('Pick a faction to loot.');
            }

            if (Crime::query()->where('target_faction_id', $faction->id)->where('kind', '!=', CrimeKind::Plunder)->where('created_at', '>=', Clock::startOfToday())->exists()) {
                throw new GameException("{$faction->name} has already been targeted today.");
            }
        } else {
            if ($target === null || $target->valley_id !== $player->valley_id) {
                throw new GameException('Pick a home to burn.');
            }

            // Only a home that stands can burn: an animal without one has nothing to set alight.
            if (Homes::of($target) === null) {
                throw new GameException("{$target->name} hasn't built a home yet. There's nothing to burn.");
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
            $onPlayer = in_array($kind, [CrimeKind::Burn, CrimeKind::Rob], true);
            $victim = $onPlayer ? $target?->faction_id : $faction?->id;
            if ($locked->faction_id !== null && $victim === $locked->faction_id) {
                RaidRules::judgeCrime($locked, $kind, $onPlayer ? $target?->name : ($region?->name ?? $faction?->name));
            }

            return Crime::create([
                'season_id' => $season->id,
                'player_id' => $locked->id,
                'kind' => $kind,
                'target_faction_id' => $onPlayer ? $target?->faction_id : $faction?->id,
                'target_player_id' => $target?->id,
                'region_id' => $region?->id,
                'settles_at' => CarbonImmutable::now()->addMinutes($kind === CrimeKind::Rob ? self::ROB_MINUTES : 30),
                'seed' => Dice::seed(),
                'cloaked' => Powers::cloak($locked),
            ]);
        });
    }

    /**
     * A robbery needs a victim close by, not a newcomer, not robbed lately,
     * and a robber who hasn't robbed lately.
     *
     * @throws GameException
     */
    private function ensureCanRob(Player $player, ?Player $target): void
    {
        if ($target === null || $target->valley_id !== $player->valley_id) {
            throw new GameException('Pick an animal to rob.');
        }

        if ($target->is($player)) {
            throw new GameException("You can't rob yourself.");
        }

        if ($target->isShielded()) {
            throw new GameException("{$target->name} is new to the valley and protected for now.");
        }

        if ($target->inPound()) {
            throw new GameException("{$target->name} is in the pound with nothing on them.");
        }

        $last = Crime::query()->where('player_id', $player->id)->where('kind', CrimeKind::Rob)->latest('id')->first();

        if ($last !== null && $last->created_at->gt(CarbonImmutable::now()->subMinutes(self::ROB_REST_MINUTES))) {
            throw new GameException('You robbed someone not long ago. Lie low until '.Clock::local($last->created_at->addMinutes(self::ROB_REST_MINUTES))->format('H:i').'.');
        }

        [$x, $y] = Movement::position($player);
        [$tx, $ty] = Movement::position($target);

        if (hypot($tx - $x, $ty - $y) > self::ROB_REACH) {
            throw new GameException("Get closer to {$target->name} first: you rob an animal where it stands.");
        }

        $robbed = Crime::query()->where('target_player_id', $target->id)->where('kind', CrimeKind::Rob)->latest('id')->first();

        if ($robbed !== null && $robbed->created_at->gt(CarbonImmutable::now()->subHours(self::ROB_GUARD_HOURS))) {
            throw new GameException("{$target->name} was robbed not long ago and is on guard. Try again after ".Clock::local($robbed->created_at->addHours(self::ROB_GUARD_HOURS))->format('H:i').'.');
        }
    }

    /** Whether the animal has already tried a loot or a burn today. */
    public static function triedToday(Player $player): bool
    {
        return Crime::query()->where('player_id', $player->id)->where('kind', '!=', CrimeKind::Plunder)->where('created_at', '>=', Clock::startOfToday())->exists();
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
            } elseif ($crime->kind === CrimeKind::Rob && $crime->target_player_id !== null) {
                // The robber takes a share of what its victim carries: Corn, and each material.
                $victim = Players::lock(Player::query()->findOrFail($crime->target_player_id));
                $loot = min(self::ROB_MOST_CORN, (int) floor($victim->corn * self::ROB_SHARE));
                $kept = $victim->materials ?? [];
                $carried = $criminal->materials ?? [];

                foreach ($kept as $name => $amount) {
                    $stolen = (int) floor((int) $amount * self::ROB_SHARE);
                    $kept[$name] = (int) $amount - $stolen;
                    $carried[$name] = (int) ($carried[$name] ?? 0) + $stolen;
                }

                $victim->forceFill(['corn' => $victim->corn - $loot, 'materials' => $kept])->save();
                $criminal->forceFill(['corn' => $criminal->corn + $loot, 'materials' => $carried])->save();
            } elseif ($crime->kind === CrimeKind::Burn && $crime->target_player_id !== null && $crime->doused_by === null) {
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
        $victims = in_array($crime->kind, [CrimeKind::Burn, CrimeKind::Rob], true) ? collect([$crime->target_player_id]) : Notices::members($crime->target_faction_id);
        $section = $crime->region?->name;

        if ($caught) {
            Notices::send($criminal->id, 'crime', '🚨 You were caught and sent to the pound until '.Clock::local($criminal->pound_until)->format('H:i').'.', $underworld);
            Notices::send($victims, 'crime', match ($crime->kind) {
                CrimeKind::Loot => "🚨 {$criminal->name} tried to loot your faction's treasury and was caught.",
                CrimeKind::Plunder => "🚨 {$criminal->name} tried to plunder {$section} and was caught.",
                CrimeKind::Burn => "🚨 {$criminal->name} tried to burn down your home and was caught.",
                CrimeKind::Rob => "🚨 {$criminal->name} tried to rob you and was caught.",
            }, $underworld);

            return;
        }

        if ($crime->kind === CrimeKind::Plunder) {
            $crop = $crime->region?->produces;
            Notices::send($criminal->id, 'crime', '🌾 You plundered '.self::PLUNDER_CROP." {$crop} and {$loot} Corn from {$section}.", route('play', [], false));
            Notices::send($victims, 'crime', "🔥 Someone plundered {$section}, taking {$loot} Corn from your treasury. Your leader can put a bounty on whoever the informants name.", route('play.wanted', [], false));

            return;
        }

        if ($crime->kind === CrimeKind::Rob) {
            $victimName = Player::query()->whereKey($crime->target_player_id)->value('name');
            Notices::send($criminal->id, 'crime', "💰 You robbed {$victimName} of {$loot} Corn and a share of what they carried.", route('play', [], false));
            Notices::send($victims, 'crime', "🦹 Someone robbed you of {$loot} Corn and a share of what you carried. The informants will name them to anyone putting up a bounty.", route('play.wanted', [], false));

            return;
        }

        if ($crime->kind === CrimeKind::Loot) {
            Notices::send($criminal->id, 'crime', "💰 You got away with {$loot} Corn.", $underworld);
            Notices::send($victims, 'crime', "🔥 Someone looted {$loot} Corn from your faction's treasury. Your leader can put a bounty on whoever the informants name.", route('play.wanted', [], false));

            return;
        }

        $target = Player::query()->whereKey($crime->target_player_id)->value('name');

        if ($crime->doused_by !== null) {
            $hero = Player::query()->whereKey($crime->doused_by)->value('name');
            Notices::send($criminal->id, 'crime', "🪣 {$hero} put out the fire you set at {$target}'s home. It still stands.", $underworld);

            return;
        }

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
        // And its skin from the shop guards it too.
        $target = $crime->kind === CrimeKind::Burn && $crime->target_player_id !== null ? Home::query()->where('player_id', $crime->target_player_id)->first() : null;
        $walls = $target !== null ? $target->level * Homes::BURN_GUARD + Homes::skinDefence($target) : 0.0;
        // Robbing an animal hiding in its home means breaking in past its walls and skin.
        if ($crime->kind === CrimeKind::Rob && $crime->target_player_id !== null) {
            $victim = Player::query()->find($crime->target_player_id);
            $walls = $victim !== null && Homes::hiding($victim) ? Homes::guard($victim) * Homes::BURN_GUARD + Homes::armour($victim) : 0.0;
        }

        // Whoever put the fire out saw who set it.
        $spotted = $crime->kind === CrimeKind::Burn && $crime->doused_by !== null ? self::DOUSE_SPOTTED : 0.0;
        $chance = min(0.9, 0.3 + 0.05 * min(4, $dogs) + $watch + Powers::bell($crime->target_faction_id) + $walls + $spotted);

        // A Night Cloak halves it.
        return $crime->cloaked ? $chance * Powers::CLOAK : $chance;
    }
}
