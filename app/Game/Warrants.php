<?php

namespace App\Game;

use App\Enums\CrimeKind;
use App\Enums\Species;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\Player;
use App\Models\Season;
use App\Models\Warrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The House's justice. Its informants know who has been committing crimes;
 * its leader and council put out warrants with a Corn bounty from the
 * treasury, anyone can add to a bounty, and any animal who catches the
 * outlaw where they stand sends them to the pound and collects it.
 */
final class Warrants
{
    public const MIN_BOUNTY = 50;

    public const MAX_BOUNTY = 2000;

    /** How long a crime keeps an animal on the House's list of suspects. */
    public const SUSPECT_DAYS = 3;

    public const ARREST_HORSESHOES = 2;

    public const ARREST_CHANCE = 0.55;

    /** Dogs make the best hunters. */
    public const DOG_BONUS = 0.15;

    /** After a failed arrest, the outlaw is out of reach this long. */
    public const ESCAPE_MINUTES = 30;

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
                throw new GameException('Put a bounty of '.self::MIN_BOUNTY.' to '.number_format(self::MAX_BOUNTY).' Corn on a warrant.');
            }

            $house = Faction::query()->lockForUpdate()->findOrFail($house->id);

            if ($house->treasury < $bounty) {
                throw new GameException("The treasury holds {$house->treasury} Corn.");
            }

            $house->decrement('treasury', $bounty);

            $warrant = Warrant::create([
                'season_id' => $season->id,
                'player_id' => $suspect->id,
                'faction_id' => $house->id,
                'bounty' => $bounty,
                'status' => 'open',
            ]);

            $wanted = route('play.wanted', [], false);
            Notices::send($suspect->id, 'warrant', "🎯 {$house->name} put a {$bounty} Corn warrant out for your arrest. Bounty hunters are coming.", $wanted);
            Notices::send(Notices::valley($player->valley_id)->reject(fn (int $id) => $id === $suspect->id), 'warrant', "🎯 Wanted: {$suspect->name}, with a {$bounty} Corn bounty from {$house->name}.", $wanted);

            return $warrant;
        });
    }

    /**
     * Add Corn of your own to a bounty.
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

            if ($amount < 10) {
                throw new GameException('Add at least 10 Corn.');
            }

            if ($player->corn < $amount) {
                throw new GameException("You have {$player->corn} Corn.");
            }

            $player->decrement('corn', $amount);
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
                throw new GameException("{$wanted->name} is in {$where->name}. Walk there to make the arrest.");
            }

            Horseshoes::spend($hunter, self::ARREST_HORSESHOES);
            $chance = self::ARREST_CHANCE + ($hunter->species === Species::Dog ? self::DOG_BONUS : 0.0);
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
            $hunter->increment('corn', $warrant->bounty);
            $warrant->forceFill(['status' => 'served', 'hunter_id' => $hunter->id, 'served_at' => CarbonImmutable::now()])->save();
            Rosettes::award($hunter, 15, "Caught {$wanted->name}", "arrest:{$warrant->id}", $hunter->valley->currentSeason());

            Notices::send($wanted->id, 'warrant', "🚨 {$hunter->name} caught you in {$where->name}. You're in the pound until ".Clock::local($wanted->pound_until)->format('H:i').'.', $wantedPage);
            Notices::send(Notices::valley($hunter->valley_id)->reject(fn (int $id) => in_array($id, [$hunter->id, $wanted->id], true)), 'warrant', "🎯 {$hunter->name} caught {$wanted->name} and collected the {$warrant->bounty} Corn bounty.", $wantedPage);

            return true;
        });
    }

    /** A new season closes the old season's warrants. */
    public function lapse(Season $season): void
    {
        Warrant::query()->where('season_id', $season->id)->where('status', 'open')->update(['status' => 'lapsed']);
    }
}
