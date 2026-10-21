<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Home;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

    /** How long a victim has to catch whoever wronged them. */
    public const CATCH_HOURS = 24;

    /** What a victim's catch costs. */
    public const CATCH_HORSESHOES = 2;

    /** How likely a victim standing by the criminal is to catch them, before any home they hide in; dogs do better. */
    public const CATCH_CHANCE = 0.8;

    private const CATCH_IT = ' Catch them where they stand and send them to the pound, or put a bounty on them, from the Wanted page.';

    /** @var list<string> The materials taken in the robbery being settled. */
    private array $haul = [];

    /** At most this much Corn in one robbery. */
    public const ROB_MOST_CORN = 250;

    /** How near a robber must stand to its victim, in paces. */
    public const ROB_REACH = 60;

    /** A robber lies low this long between robberies, and a victim is left alone this long after being robbed. */
    public const ROB_REST_MINUTES = 120;

    public const ROB_GUARD_HOURS = 6;

    /** A robbery is over quickly: it settles after this long. */
    public const ROB_MINUTES = 10;

    /** How far short of its target a robber or a firebug stops, in paces. */
    public const BESIDE = 30;

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

            // A robbery or a burning is struck from wherever you stand: you walk up beside them, and it lands as you arrive.
            $path = $onPlayer && $target !== null ? self::path($locked, $kind, $target) : null;

            if ($path !== null) {
                $path['lands_at'] = app(Movement::class)->strikeOut($locked, [$path['to_x'], $path['to_y']]);
            }
            $base = $kind === CrimeKind::Rob ? self::ROB_MINUTES : 30;

            $crime = Crime::create([
                'season_id' => $season->id,
                'player_id' => $locked->id,
                'kind' => $kind,
                'target_faction_id' => $onPlayer ? $target?->faction_id : $faction?->id,
                'target_player_id' => $target?->id,
                'region_id' => $region?->id,
                // A robbery happens as the robber arrives; a fire burns a while first, so someone can put it out.
                'settles_at' => match (true) {
                    $path === null => CarbonImmutable::now()->addMinutes($base),
                    $kind === CrimeKind::Rob => $path['lands_at'],
                    default => $path['lands_at']->max(CarbonImmutable::now()->addMinutes($base)),
                },
                'seed' => Dice::seed(),
                'cloaked' => Powers::cloak($locked),
            ]);

            if ($path !== null) {
                $crime->forceFill($path)->save();
                // The one it's aimed at sees it coming.
                $what = $kind === CrimeKind::Rob ? 'to rob you' : 'to set your home alight';
                Notices::send($target->id, 'crime', "🎯 {$locked->name} is on the way {$what}. They get there at ".Clock::local($path['lands_at'])->format('H:i').'. Hide in your home, or be ready.', route('play', [], false));
                Live::touch($locked->valley_id);
            }

            // A robber's cudgel goes with it.
            if ($kind === CrimeKind::Rob && Powers::cudgel($locked)) {
                $crime->forceFill(['armed' => true])->save();
            }

            return $crime;
        });
    }

    /**
     * Where a robbery or a burning goes, from where the criminal stands, and
     * when it lands: the further, and the slower its pace, the later.
     *
     * @return array{from_x: float, from_y: float, to_x: float, to_y: float, lands_at: CarbonImmutable}
     */
    private static function path(Player $player, CrimeKind $kind, Player $target): array
    {
        [$x, $y] = Movement::position($player);
        $home = $kind === CrimeKind::Burn ? Homes::of($target) : null;
        [$tx, $ty] = $home !== null ? [$home->x, $home->y] : Movement::spot($target);
        // Stop right beside them, not on top of them.
        $gap = hypot($tx - $x, $ty - $y);
        $beside = min($gap, self::BESIDE);
        if ($gap > 0) {
            $tx -= ($tx - $x) / $gap * $beside;
            $ty -= ($ty - $y) / $gap * $beside;
        }
        $minutes = hypot($tx - $x, $ty - $y) / max(1, Movement::pace($player));

        return [
            'from_x' => round($x, 1),
            'from_y' => round($y, 1),
            'to_x' => round($tx, 1),
            'to_y' => round($ty, 1),
            'lands_at' => CarbonImmutable::now()->addSeconds((int) ceil($minutes * 60)),
        ];
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

        if ($target->inPound()) {
            throw new GameException("{$target->name} is in the pound with nothing on them.");
        }

        $last = Crime::query()->where('player_id', $player->id)->where('kind', CrimeKind::Rob)->latest('id')->first();

        if ($last !== null && $last->created_at->gt(CarbonImmutable::now()->subMinutes(self::ROB_REST_MINUTES))) {
            throw new GameException('You robbed someone not long ago. Lie low until '.Clock::local($last->created_at->addMinutes(self::ROB_REST_MINUTES))->format('H:i').'.');
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

    /**
     * The crimes against an animal, or its faction, that it can still catch
     * the criminal for.
     *
     * @return Collection<int, Crime>
     */
    public static function against(Player $victim): Collection
    {
        return Crime::query()
            ->with(['player.faction:id,name,colour', 'region:id,name', 'targetFaction:id,name', 'targetPlayer:id,name'])
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', CarbonImmutable::now()->subHours(self::CATCH_HOURS))
            ->where('caught', false)
            ->whereNull('caught_by')
            ->where('player_id', '!=', $victim->id)
            ->where(fn ($query) => $query->where('target_player_id', $victim->id)
                ->when($victim->faction_id !== null, fn ($query) => $query->orWhere('target_faction_id', $victim->faction_id)))
            ->latest('id')
            ->get();
    }

    /**
     * The victim catches whoever wronged them, standing where they stand:
     * to the pound with them, and they pay back what this crime took. A
     * miss lets them slip away for half an hour.
     *
     * @param  float|null  $roll  A fixed roll, for tests; otherwise luck decides.
     *
     * @throws GameException
     */
    public function catchCulprit(Player $victim, Crime $crime, ?float $roll = null): bool
    {
        return DB::transaction(function () use ($victim, $crime, $roll) {
            $crime = Crime::query()->lockForUpdate()->findOrFail($crime->id);
            $victim = Players::lock($victim);
            Players::ensureFree($victim);

            if (self::against($victim)->doesntContain('id', $crime->id)) {
                throw new GameException('That crime is too old to catch anyone for, or someone already caught them.');
            }

            $criminal = Players::lock($crime->player);

            if ($criminal->inPound()) {
                throw new GameException("{$criminal->name} is already in the pound.");
            }

            if ($crime->escaped_until !== null && $crime->escaped_until->isFuture()) {
                throw new GameException("{$criminal->name} slipped away. You can pick up the trail at ".Clock::local($crime->escaped_until)->format('H:i').'.');
            }

            $where = $criminal->location();

            if ($victim->location()->id !== $where->id) {
                throw new GameException($where->isCity()
                    ? "{$criminal->name} is hiding somewhere in Irongate. Find the district, or pay Wick in the Iron Yards to tell you."
                    : "{$criminal->name} is in {$where->name}. Walk there to catch them.");
            }

            Horseshoes::spend($victim, self::CATCH_HORSESHOES);
            $chance = self::CATCH_CHANCE + ($victim->species === Species::Dog ? Warrants::DOG_BONUS : 0.0);
            // A black cat melts into the night.
            if ($criminal->species === Species::CatBlack) {
                $chance -= 0.2;
            }
            $chance -= Homes::arrestCut(Homes::guard($criminal), $chance);
            $chance -= min(Homes::armour($criminal), max(0.0, $chance - Homes::ARREST_FLOOR));
            $roll ??= Dice::roll(Dice::seed(), 'catch');
            $wanted = route('play.wanted', [], false);

            if ($roll >= $chance) {
                $crime->forceFill(['escaped_until' => CarbonImmutable::now()->addMinutes(Warrants::ESCAPE_MINUTES)])->save();
                Notices::send($criminal->id, 'crime', "🏃 {$victim->name} tried to catch you in {$where->name}. You slipped away, for now.", $wanted);

                return false;
            }

            $criminal->forceFill([
                'pound_until' => CarbonImmutable::now()->addHours(self::POUND_HOURS),
                'wanted' => $criminal->wanted + 1,
            ])->save();

            // They pay back what this crime took, as far as their Corn goes.
            $owed = min($crime->kind === CrimeKind::Burn && $crime->doused_by === null ? self::REBUILD_CORN : (int) $crime->loot, $criminal->corn);

            if ($owed > 0) {
                $criminal->decrement('corn', $owed);

                if ($crime->target_faction_id !== null && in_array($crime->kind, [CrimeKind::Loot, CrimeKind::Plunder], true)) {
                    Faction::query()->whereKey($crime->target_faction_id)->increment('treasury', $owed);
                } else {
                    $victim->increment('corn', $owed);
                }
            }

            $crime->forceFill(['caught' => true, 'caught_by' => $victim->id, 'repaid_at' => CarbonImmutable::now()])->save();
            Rosettes::award($victim, 10, "Caught {$criminal->name}", "catch:{$crime->id}", $victim->valley->currentSeason());
            $reward = Wall::justice($victim, $criminal);

            if ($reward !== '') {
                Notices::send($victim->id, 'crime', '💰'.$reward, $wanted);
            }
            Missions::record($victim, 'bounty');
            $back = $owed > 0 ? " They paid back {$owed} Corn." : '';
            Notices::send($criminal->id, 'crime', "🚨 {$victim->name} caught you in {$where->name}. You're in the pound until ".Clock::local($criminal->fresh()->pound_until)->format('H:i').'.'.($owed > 0 ? " You paid back {$owed} Corn." : ''), $wanted);

            if ($crime->target_faction_id !== null) {
                Notices::send(Notices::members($crime->target_faction_id)->reject(fn ($id) => $id === $victim->id), 'crime', "🚨 {$victim->name} caught {$criminal->name} and sent them to the pound.{$back}", $wanted);
            }

            return true;
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
        DB::transaction(function () use ($crime) {
            $crime = Crime::query()->lockForUpdate()->findOrFail($crime->id);

            if ($crime->resolved_at !== null) {
                return;
            }

            $criminal = Players::lock($crime->player);
            // Nobody is caught by chance any more: the victim catches the criminal, or puts a bounty on them (her call, 2026-10-20).
            $caught = false;
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
                // The plunderer strips the field bare into its bag (her rule, 2026-10-21), and takes Corn from its holders.
                $region = Region::query()->lockForUpdate()->findOrFail($crime->region_id);
                $taken = Stock::strip($region, $criminal);

                $this->haul[] = "{$taken} {$region->produces}";

                $faction = $crime->target_faction_id !== null ? Faction::query()->lockForUpdate()->find($crime->target_faction_id) : null;

                if ($faction !== null) {
                    $loot = min(self::PLUNDER_CORN, $faction->treasury);
                    $faction->decrement('treasury', $loot);
                    $criminal->increment('corn', $loot);
                }
            } elseif ($crime->kind === CrimeKind::Rob && $crime->target_player_id !== null) {
                // The robber takes a share of what its victim carries: Corn, and each material.
                $victim = Players::lock(Player::query()->findOrFail($crime->target_player_id));
                // A cudgel takes twice as much.
                $share = self::ROB_SHARE * ($crime->armed ? Powers::CUDGEL_LOOT : 1);
                // A tortoise keeps most of what it carries under its shell, and so does a homebody cat.
                if (in_array($victim->species, [Species::Tortoise, Species::CatChocolate], true)) {
                    $share /= 2;
                }

                // A masked cat makes off with half as much again.
                if ($criminal->species === Species::CatMasked) {
                    $share *= 1.5;
                }
                $loot = min(self::ROB_MOST_CORN * ($crime->armed ? Powers::CUDGEL_LOOT : 1), (int) floor($victim->corn * $share));
                $kept = $victim->materials ?? [];
                $carried = $criminal->materials ?? [];

                foreach ($kept as $name => $amount) {
                    $stolen = (int) floor((int) $amount * $share);
                    $kept[$name] = (int) $amount - $stolen;
                    $carried[$name] = (int) ($carried[$name] ?? 0) + $stolen;

                    if ($stolen > 0) {
                        $this->haul[] = "{$stolen} {$name}";
                    }
                }

                $victim->forceFill(['corn' => $victim->corn - $loot, 'materials' => $kept])->save();
                $criminal->forceFill(['corn' => $criminal->corn + $loot, 'materials' => $carried])->save();
                // And roughed up.
                $victim->forceFill(['health' => max(1, (float) $victim->health - (float) config('game.survival.robbed'))])->save();
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
        // What a robber made off with besides Corn, as "12 wood, 3 honey".
        $haul = $this->haul;
        $this->haul = [];
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
            $crop = $haul[0] ?? '0 '.$crime->region?->produces;
            Notices::send($criminal->id, 'crime', "🌾 You stripped {$section} bare: {$crop} and {$loot} Corn into your bag.", route('play', [], false));
            Notices::send($victims, 'crime', "🔥 {$criminal->name} plundered {$section}, taking {$loot} Corn from your treasury.".self::CATCH_IT, route('play.wanted', [], false));

            return;
        }

        if ($crime->kind === CrimeKind::Rob) {
            $victimName = Player::query()->whereKey($crime->target_player_id)->value('name');
            $took = $haul === [] ? "{$loot} Corn" : "{$loot} Corn, ".implode(', ', $haul);
            Notices::send($criminal->id, 'crime', "💰 You robbed {$victimName} of {$took}.", route('play', [], false));
            Notices::send($victims, 'crime', "🦹 {$criminal->name} robbed you of {$took}.".self::CATCH_IT, route('play.wanted', [], false));

            return;
        }

        if ($crime->kind === CrimeKind::Loot) {
            Notices::send($criminal->id, 'crime', "💰 You got away with {$loot} Corn.", $underworld);
            Notices::send($victims, 'crime', "🔥 {$criminal->name} looted {$loot} Corn from your faction's treasury.".self::CATCH_IT, route('play.wanted', [], false));

            return;
        }

        $target = Player::query()->whereKey($crime->target_player_id)->value('name');

        if ($crime->doused_by !== null) {
            $hero = Player::query()->whereKey($crime->doused_by)->value('name');
            Notices::send($criminal->id, 'crime', "🪣 {$hero} put out the fire you set at {$target}'s home. It still stands.", $underworld);

            return;
        }

        Notices::send($criminal->id, 'crime', "🔥 You burned down {$target}'s home.", $underworld);
        Notices::send($victims, 'crime', "🔥 {$criminal->name} burned your home down. Your shop is closed for a day, or rebuild it now for ".self::REBUILD_CORN.' Corn.'.self::CATCH_IT, route('play.wanted', [], false));
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
            Home::query()->where('player_id', $player->id)->update(['health' => null, 'health_at' => null]);
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

        // A robber's cudgel makes it quicker.
        if ($crime->armed) {
            $chance *= Powers::CUDGEL_CATCH;
        }

        // A Night Cloak halves it.
        return $crime->cloaked ? $chance * Powers::CLOAK : $chance;
    }
}
