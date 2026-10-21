<?php

namespace App\Game;

use App\Models\Alliance;
use App\Models\Faction;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Alliances between factions (her ask, 2026-10-22). A faction's leader or
 * council offers one; the other side's leader or council accepts. Allies
 * can't raid each other, may defend each other's land, and live on each
 * other's land without being moved off. Either side can break it at any
 * time, and the whole valley hears of the betrayal. One ally at a time.
 */
final class Alliances
{
    /** The alliance a faction is in now, if any. */
    public static function active(?int $factionId): ?Alliance
    {
        if ($factionId === null) {
            return null;
        }

        return Alliance::query()
            ->with(['faction:id,name,colour', 'ally:id,name,colour'])
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('faction_id', $factionId)->orWhere('ally_id', $factionId))
            ->first();
    }

    /** The faction's ally's id, if it has one. */
    public static function allyOf(?int $factionId): ?int
    {
        $alliance = self::active($factionId);

        if ($alliance === null) {
            return null;
        }

        return $alliance->faction_id === $factionId ? $alliance->ally_id : $alliance->faction_id;
    }

    public static function allied(?int $a, ?int $b): bool
    {
        return $a !== null && $b !== null && $a !== $b && self::allyOf($a) === $b;
    }

    /**
     * Offer an alliance to another faction.
     *
     * @throws GameException
     */
    public function propose(Player $player, Faction $other): Alliance
    {
        $mine = $this->decider($player);

        if ($other->id === $mine->id || $other->valley_id !== $mine->valley_id) {
            throw new GameException('Pick another faction in your valley.');
        }

        if (self::active($mine->id) !== null) {
            throw new GameException('Your faction already has an ally. Break that alliance first.');
        }

        if (self::active($other->id) !== null) {
            throw new GameException("{$other->name} already has an ally.");
        }

        if (Alliance::query()->where('status', 'proposed')->where(fn ($q) => $q
            ->where(fn ($pair) => $pair->where('faction_id', $mine->id)->where('ally_id', $other->id))
            ->orWhere(fn ($pair) => $pair->where('faction_id', $other->id)->where('ally_id', $mine->id)))->exists()) {
            throw new GameException("There's already an offer between you and {$other->name}. It waits on their answer, or yours.");
        }

        $alliance = Alliance::create(['faction_id' => $mine->id, 'ally_id' => $other->id, 'status' => 'proposed']);
        Notices::send(Notices::deciders($other->id), 'faction', "🤝 {$mine->name} offers {$other->name} an alliance. Accept or decline it on your Faction page.", route('play.faction', [], false));

        return $alliance;
    }

    /**
     * Accept or decline an offer made to your faction.
     *
     * @throws GameException
     */
    public function answer(Player $player, Alliance $alliance, bool $accept): void
    {
        $mine = $this->decider($player);

        DB::transaction(function () use ($mine, $alliance, $accept) {
            $alliance = Alliance::query()->lockForUpdate()->findOrFail($alliance->id);

            if ($alliance->ally_id !== $mine->id || $alliance->status !== 'proposed') {
                throw new GameException('That offer is no longer open.');
            }

            $from = Faction::query()->findOrFail($alliance->faction_id);

            if (! $accept) {
                $alliance->forceFill(['status' => 'declined'])->save();
                Notices::send(Notices::deciders($from->id), 'faction', "{$mine->name} declined your alliance.", route('play.faction', [], false));

                return;
            }

            if (self::active($mine->id) !== null || self::active($from->id) !== null) {
                throw new GameException('One of you already has an ally.');
            }

            $alliance->forceFill(['status' => 'active'])->save();

            // Other offers to or from either side are off.
            Alliance::query()->where('status', 'proposed')->where(fn ($q) => $q
                ->whereIn('faction_id', [$mine->id, $from->id])->orWhereIn('ally_id', [$mine->id, $from->id]))
                ->update(['status' => 'declined']);

            Notices::send(Notices::valley($mine->valley_id), 'faction', "🤝 {$from->name} and {$mine->name} are now allies.", route('play.valley', [], false));
        });
    }

    /**
     * Break your faction's alliance: a betrayal the whole valley hears about.
     *
     * @throws GameException
     */
    public function break(Player $player): void
    {
        $mine = $this->decider($player);
        $alliance = self::active($mine->id) ?? throw new GameException('Your faction has no ally.');
        $other = $alliance->other($mine->id);

        $alliance->forceFill(['status' => 'broken', 'broken_by' => $mine->id, 'broken_at' => CarbonImmutable::now()])->save();
        Notices::send(Notices::valley($mine->valley_id), 'faction', "💔 {$mine->name} broke its alliance with {$other?->name}. Their land is fair game again.", route('play.valley', [], false));
    }

    /**
     * Your faction's alliance and the offers waiting on it, for the Faction page.
     *
     * @return array<string, mixed>
     */
    public static function page(Player $player): array
    {
        $alliance = self::active($player->faction_id);
        $other = $alliance?->other((int) $player->faction_id);

        return [
            'ally' => $other ? ['id' => $other->id, 'name' => $other->name, 'colour' => $other->colour, 'since' => $alliance->updated_at?->toIso8601String()] : null,
            'offers' => Alliance::query()->with('faction:id,name,colour')->where('ally_id', $player->faction_id)->where('status', 'proposed')->get()
                ->map(fn (Alliance $offer) => ['id' => $offer->id, 'from' => ['id' => $offer->faction->id, 'name' => $offer->faction->name, 'colour' => $offer->faction->colour]])->all(),
            'canDecide' => $player->decides(),
        ];
    }

    /** @throws GameException */
    private function decider(Player $player): Faction
    {
        $faction = $player->faction ?? throw new GameException('Join a faction first.');

        if (! $player->decides()) {
            throw new GameException("Only your faction's leader and council make or break alliances.");
        }

        return $faction;
    }
}
