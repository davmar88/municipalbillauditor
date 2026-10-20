<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Models\AppleEntry;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Season;
use App\Models\Warrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bounties. The informants know who has been committing crimes; any
 * faction's leader and council can put out a warrant with a bounty of
 * Apples from their faction's store, anyone can add Apples of their own,
 * and any animal who catches the outlaw where they stand sends them to the
 * pound and collects the bounty, less the game's share. The outlaw pays
 * back what their crimes took, as far as their Corn goes. A warrant nobody
 * serves by the season's end hands every Apple back to whoever put them up.
 */
final class Warrants
{
    /** Bounties are in Apples. */
    public const MIN_BOUNTY = 5;

    public const MAX_BOUNTY = 500;

    /** How long a crime keeps an animal on the House's list of suspects. */
    public const SUSPECT_DAYS = 3;

    public const ARREST_HORSESHOES = 2;

    public const ARREST_CHANCE = 0.55;

    /** Dogs make the best hunters. */
    public const DOG_BONUS = 0.15;

    /** After a failed arrest, the outlaw is out of reach this long. */
    public const ESCAPE_MINUTES = 30;

    /** The share of a bounty the game keeps, in percent. */
    public static function cut(): int
    {
        return (int) config('game.apples.bounty_cut');
    }

    /** What the hunter who serves a warrant collects. */
    public static function payout(int $bounty): int
    {
        return intdiv($bounty * (100 - self::cut()), 100);
    }

    /**
     * Animals the House's informants have seen commit crimes lately, with
     * what each did last.
     *
     * @return Collection<int, array{player: Player, crime: Crime}>
     */
    public static function suspects(Season $season): Collection
    {
        return Crime::query()
            ->with(['player.faction:id,name,colour', 'region:id,name', 'targetFaction:id,name', 'targetPlayer:id,name'])
            ->where('season_id', $season->id)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(self::SUSPECT_DAYS))
            ->latest('id')
            ->get()
            ->unique('player_id')
            ->values()
            ->map(fn (Crime $crime) => ['player' => $crime->player, 'crime' => $crime]);
    }

    /** What the informants say an animal did, in a few words. */
    public static function deed(Crime $crime): string
    {
        return match ($crime->kind) {
            CrimeKind::Loot => 'looted '.($crime->targetFaction?->name ?? 'a faction'),
            CrimeKind::Burn => 'burned down '.($crime->targetPlayer?->name ?? 'someone')."'s home",
            CrimeKind::Plunder => 'plundered '.($crime->region?->name ?? 'a farm'),
            CrimeKind::Rob => 'robbed '.($crime->targetPlayer?->name ?? 'someone'),
        };
    }

    /**
     * @throws GameException
     */
    public function issue(Player $player, Player $suspect, int $bounty): Warrant
    {
        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');

        return DB::transaction(function () use ($player, $suspect, $bounty, $season) {
            $player = Players::lock($player);

            if ($player->faction_id === null || ! $player->decides()) {
                throw new GameException("Only a faction's leader and council put out warrants.");
            }

            if ($suspect->valley_id !== $player->valley_id || self::suspects($season)->doesntContain(fn (array $s) => $s['player']->id === $suspect->id)) {
                throw new GameException("{$suspect->name} has done nothing the informants know of.");
            }

            if ($suspect->id === $player->id) {
                throw new GameException("You can't put a bounty on yourself.");
            }

            if (Warrant::query()->where('season_id', $season->id)->where('player_id', $suspect->id)->where('status', 'open')->exists()) {
                throw new GameException("There's already a warrant out for {$suspect->name}. Add to its bounty instead.");
            }

            if ($bounty < self::MIN_BOUNTY || $bounty > self::MAX_BOUNTY) {
                throw new GameException('Put a bounty of '.self::MIN_BOUNTY.' to '.self::MAX_BOUNTY.' Apples on a warrant.');
            }

            $house = Faction::query()->lockForUpdate()->findOrFail($player->faction_id);

            if ($house->apples < $bounty) {
                throw new GameException("{$house->name}'s store holds ".self::apples($house->apples).'. Members can give Apples from the Faction page.');
            }

            $house->decrement('apples', $bounty);

            Missions::record($player, 'bounty');

            $warrant = Warrant::create([
                'season_id' => $season->id,
                'player_id' => $suspect->id,
                'faction_id' => $house->id,
                'bounty' => $bounty,
                'status' => 'open',
            ]);

            $wanted = route('play.wanted', [], false);
            Notices::send($suspect->id, 'warrant', "🎯 {$house->name} put a warrant out for your arrest, with a bounty of {$bounty} Apples. Bounty hunters are coming.", $wanted);
            Notices::send(Notices::valley($player->valley_id)->reject(fn (int $id) => $id === $suspect->id), 'warrant', "🎯 Wanted: {$suspect->name}, with a bounty of {$bounty} Apples from {$house->name}.", $wanted);

            return $warrant;
        });
    }

    /**
     * Add Apples of your own to a bounty.
     *
     * @throws GameException
     */
    public function raise(Player $player, Warrant $warrant, int $amount): void
    {
        DB::transaction(function () use ($player, $warrant, $amount) {
            $player = Players::lock($player);
            $warrant = Warrant::query()->lockForUpdate()->findOrFail($warrant->id);

            if ($warrant->status !== 'open') {
                throw new GameException('That warrant is closed.');
            }

            if ($warrant->player_id === $player->id) {
                throw new GameException("You can't put a bounty on yourself.");
            }

            if ($amount < 1) {
                throw new GameException('Add at least 1 Apple.');
            }

            Apples::spend($player, $amount, "Added to the bounty on {$warrant->wanted->name}", "bounty:{$warrant->id}:raise:".Str::uuid());
            Missions::record($player, 'bounty');
            $warrant->increment('bounty', $amount);
        });
    }

    /**
     * Try to arrest a wanted animal standing in the same region. A catch
     * sends them to the pound and pays the bounty; a miss lets them slip away
     * for half an hour.
     *
     * @param  float|null  $roll  A fixed roll, for tests; otherwise luck decides.
     *
     * @throws GameException
     */
    public function arrest(Player $hunter, Warrant $warrant, ?float $roll = null): bool
    {
        return DB::transaction(function () use ($hunter, $warrant, $roll) {
            $warrant = Warrant::query()->lockForUpdate()->findOrFail($warrant->id);
            $hunter = Players::lock($hunter);
            Players::ensureFree($hunter);

            if ($warrant->status !== 'open') {
                throw new GameException('That warrant is closed.');
            }

            if ($warrant->player_id === $hunter->id) {
                throw new GameException("You can't arrest yourself.");
            }

            $wanted = Players::lock($warrant->wanted);

            if ($wanted->inPound()) {
                throw new GameException("{$wanted->name} is already in the pound.");
            }

            if ($warrant->escaped_until !== null && $warrant->escaped_until->isFuture()) {
                throw new GameException("{$wanted->name} slipped away. You can pick up the trail at ".Clock::local($warrant->escaped_until)->format('H:i').'.');
            }

            $where = $wanted->location();

            if ($hunter->location()->id !== $where->id) {
                // Lying low in Irongate: the town is all anyone knows, unless they ask Wick.
                throw new GameException($where->isCity()
                    ? "{$wanted->name} is hiding somewhere in Irongate. Find the district, or pay Wick in the Iron Yards to tell you."
                    : "{$wanted->name} is in {$where->name}. Walk there to make the arrest.");
            }

            Horseshoes::spend($hunter, self::ARREST_HORSESHOES);
            // Hiding in a home makes it harder: each level of it guards the door.
            $chance = self::ARREST_CHANCE + ($hunter->species === Species::Dog ? self::DOG_BONUS : 0.0);
            $chance -= Homes::arrestCut(Homes::guard($wanted), $chance);
            // A skin from the shop guards the door too, down to the same floor.
            $chance -= min(Homes::armour($wanted), max(0.0, $chance - Homes::ARREST_FLOOR));
            $roll ??= Dice::roll(Dice::seed(), 'arrest');
            $wantedPage = route('play.wanted', [], false);

            if ($roll >= $chance) {
                $warrant->forceFill(['escaped_until' => CarbonImmutable::now()->addMinutes(self::ESCAPE_MINUTES)])->save();
                Notices::send($wanted->id, 'warrant', "🏃 {$hunter->name} tried to arrest you in {$where->name}. You slipped away, for now.", $wantedPage);

                return false;
            }

            $wanted->forceFill([
                'pound_until' => CarbonImmutable::now()->addHours(Crimes::POUND_HOURS),
                'wanted' => $wanted->wanted + 1,
            ])->save();
            // The hunter collects the bounty; the game keeps its share.
            $apples = self::payout($warrant->bounty);
            Apples::award($hunter, $apples, "Caught {$wanted->name}", "bounty:{$warrant->id}");
            $warrant->forceFill(['status' => 'served', 'hunter_id' => $hunter->id, 'served_at' => CarbonImmutable::now()])->save();
            Rosettes::award($hunter, 15, "Caught {$wanted->name}", "arrest:{$warrant->id}", $hunter->valley->currentSeason());
            Missions::record($hunter, 'bounty');
            $repaid = self::payBack($wanted, $warrant->season_id);
            $owing = $repaid > 0 ? " You paid back {$repaid} Corn to those your crimes hurt." : '';

            Notices::send($wanted->id, 'warrant', "🚨 {$hunter->name} caught you in {$where->name}. You're in the pound until ".Clock::local($wanted->pound_until)->format('H:i').".{$owing}", $wantedPage);
            Notices::send(Notices::valley($hunter->valley_id)->reject(fn (int $id) => in_array($id, [$hunter->id, $wanted->id], true)), 'warrant', "🎯 {$hunter->name} caught {$wanted->name} and collected a bounty of {$apples} Apples.", $wantedPage);

            return true;
        });
    }

    /**
     * A new season closes the old season's warrants. Nobody collected their
     * bounties, so every Apple goes back: what animals added to them, and
     * the rest to the store of the House that put them out.
     */
    public function lapse(Season $season): void
    {
        $warrants = Warrant::query()->with('wanted:id,name')->where('season_id', $season->id)->where('status', 'open')->lockForUpdate()->get();

        foreach ($warrants as $warrant) {
            /** @var Collection<int, int> $added Apples each animal added, by player id. */
            $added = AppleEntry::query()
                ->where('reference', 'like', "bounty:{$warrant->id}:raise:%")
                ->groupBy('player_id')
                ->selectRaw('player_id, -SUM(amount) as apples')
                ->pluck('apples', 'player_id')
                ->map(fn ($apples) => (int) $apples);

            foreach ($added as $playerId => $apples) {
                $player = Player::find($playerId);

                if ($player !== null && Apples::award($player, $apples, "The warrant on {$warrant->wanted->name} lapsed", "bounty:{$warrant->id}:back:{$playerId}")) {
                    Notices::send($playerId, 'warrant', "🍎 Nobody caught {$warrant->wanted->name} before the season ended. The ".self::apples($apples).' you added to the bounty came back to you.', route('play.wanted', [], false));
                }
            }

            $house = $warrant->bounty - $added->sum();

            if ($house > 0) {
                Faction::query()->whereKey($warrant->faction_id)->increment('apples', $house);
            }

            $warrant->forceFill(['status' => 'lapsed'])->save();
        }
    }

    /**
     * A caught outlaw pays back what its crimes this season took, as far as
     * its Corn goes: the Corn it looted or plundered to the faction it robbed,
     * and the cost of rebuilding to anyone whose home it burned down.
     */
    private static function payBack(Player $outlaw, int $seasonId): int
    {
        $crimes = Crime::query()
            ->where('player_id', $outlaw->id)
            ->where('season_id', $seasonId)
            ->whereNotNull('resolved_at')
            ->where('caught', false)
            ->whereNull('repaid_at')
            ->whereNull('doused_by')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $paid = 0;

        foreach ($crimes as $crime) {
            $owed = $crime->kind === CrimeKind::Burn ? Crimes::REBUILD_CORN : $crime->loot;
            $owed = min($owed, $outlaw->corn);

            if ($owed > 0) {
                if (in_array($crime->kind, [CrimeKind::Burn, CrimeKind::Rob], true) && $crime->target_player_id !== null) {
                    Player::query()->whereKey($crime->target_player_id)->increment('corn', $owed);
                    Notices::send($crime->target_player_id, 'warrant', "🌽 {$outlaw->name} was caught and paid you {$owed} Corn for ".($crime->kind === CrimeKind::Rob ? 'robbing you.' : 'burning down your home.'), route('play.wanted', [], false));
                } elseif ($crime->target_faction_id !== null) {
                    Faction::query()->whereKey($crime->target_faction_id)->increment('treasury', $owed);
                    Notices::send(Notices::deciders($crime->target_faction_id), 'warrant', "🌽 {$outlaw->name} was caught and paid back {$owed} Corn to your treasury.", route('play.wanted', [], false));
                } else {
                    $owed = 0;
                }

                $outlaw->corn -= $owed;
                $paid += $owed;
            }

            $crime->forceFill(['repaid_at' => CarbonImmutable::now()])->save();
        }

        $outlaw->save();

        return $paid;
    }

    /** "1 Apple", "12 Apples". */
    private static function apples(int $count): string
    {
        return $count === 1 ? '1 Apple' : "{$count} Apples";
    }
}
