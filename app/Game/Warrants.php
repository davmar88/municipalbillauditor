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
 * The House's justice. Its informants know who has been committing crimes;
 * its leader and council put out warrants with a bounty of Apples from the
 * House's store, anyone can add Apples of their own, and any animal who
 * catches the outlaw where they stand sends them to the pound and collects
 * the bounty, less the game's share. A warrant nobody serves by the
 * season's end hands every Apple back to whoever put them up.
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
        };
    }

    /**
     * @throws GameException
     */
    public function issue(Player $player, Player $suspect, int $bounty): Warrant
    {
        $season = $player->valley->currentSeason() ?? throw new GameException('The season is changing. Try again in a moment.');

        return DB::transaction(function () use ($player, $suspect, $bounty, $season) {
            $house = $season->valley->house() ?? throw new GameException('There is no House to put out warrants.');
            $player = Players::lock($player);

            if ($player->faction_id !== $house->id || ! $player->decides()) {
                throw new GameException("Only the House's leader and council put out warrants.");
            }

            if ($suspect->valley_id !== $player->valley_id || self::suspects($season)->doesntContain(fn (array $s) => $s['player']->id === $suspect->id)) {
                throw new GameException("{$suspect->name} has done nothing the House's informants know of.");
            }

            if (Warrant::query()->where('season_id', $season->id)->where('player_id', $suspect->id)->where('status', 'open')->exists()) {
                throw new GameException("There's already a warrant out for {$suspect->name}. Add to its bounty instead.");
            }

            if ($bounty < self::MIN_BOUNTY || $bounty > self::MAX_BOUNTY) {
                throw new GameException('Put a bounty of '.self::MIN_BOUNTY.' to '.self::MAX_BOUNTY.' Apples on a warrant.');
            }

            $house = Faction::query()->lockForUpdate()->findOrFail($house->id);

            if ($house->apples < $bounty) {
                throw new GameException("The House's store holds ".self::apples($house->apples).'. Members can give Apples from the Faction page.');
            }

            $house->decrement('apples', $bounty);

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

            Notices::send($wanted->id, 'warrant', "🚨 {$hunter->name} caught you in {$where->name}. You're in the pound until ".Clock::local($wanted->pound_until)->format('H:i').'.', $wantedPage);
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

    /** "1 Apple", "12 Apples". */
    private static function apples(int $count): string
    {
        return $count === 1 ? '1 Apple' : "{$count} Apples";
    }
}
