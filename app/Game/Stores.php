<?php

namespace App\Game;

use App\Models\Building;
use App\Models\Faction;

/**
 * Where a faction keeps its Corn and materials: in its buildings. Every
 * faction can keep a little in hand; each building it raises holds more,
 * the Den and the Storehouse most of all. A full store takes no more, and
 * raiders who take a building carry off what it held.
 */
final class Stores
{
    /** What a faction keeps in hand with no buildings at all. */
    public const BASE_CORN = 300;

    public const BASE_MATERIAL = 100;

    /**
     * How much a faction's store holds: Corn, and of each material.
     *
     * @return array{corn: int, material: int}
     */
    public static function capacity(Faction $faction): array
    {
        $corn = self::BASE_CORN;
        $material = self::BASE_MATERIAL;

        foreach (Bases::standing($faction) as $building) {
            ['corn' => $c, 'material' => $m] = $building->kind->storage($building->workingLevel());
            $corn += $c;
            $material += $m;
        }

        return ['corn' => $corn, 'material' => $material];
    }

    /** How much more of something the store has room for. */
    public static function room(Faction $faction, string $what): int
    {
        $capacity = self::capacity($faction);

        return $what === 'corn'
            ? max(0, $capacity['corn'] - $faction->treasury)
            : max(0, $capacity['material'] - $faction->material($what));
    }

    /**
     * Put Corn in a faction's store, as much as it has room for. What
     * doesn't fit is lost.
     *
     * @return int What went in.
     */
    public static function addCorn(?int $factionId, int $amount): int
    {
        $faction = $factionId !== null ? Faction::query()->find($factionId) : null;

        if ($faction === null || $amount <= 0) {
            return 0;
        }

        $added = min($amount, self::room($faction, 'corn'));

        if ($added > 0) {
            Faction::query()->whereKey($faction->id)->increment('treasury', $added);
        }

        return $added;
    }

    /**
     * Put Corn and materials in a faction's store, as much as fits. The
     * faction must be locked.
     *
     * @param  array<string, int>  $materials
     */
    public static function add(Faction $faction, int $corn, array $materials): void
    {
        $faction->treasury += min($corn, self::room($faction, 'corn'));
        $kept = $faction->materials ?? [];

        foreach ($materials as $material => $amount) {
            $kept[$material] = $faction->material($material) + min($amount, self::room($faction, $material));
        }

        $faction->materials = $kept;
        $faction->save();
    }

    /**
     * Empty a building's share of the store, as raiders take it: the part of
     * the faction's Corn and materials that building held. The faction must
     * be locked.
     *
     * @return array{corn: int, materials: array<string, int>} What it held.
     */
    public static function emptyOut(Faction $faction, Building $building): array
    {
        $capacity = self::capacity($faction);
        ['corn' => $corn, 'material' => $material] = $building->kind->storage($building->workingLevel());
        $taken = ['corn' => (int) floor($faction->treasury * $corn / max(1, $capacity['corn'])), 'materials' => []];
        $kept = $faction->materials ?? [];

        foreach ($kept as $name => $amount) {
            $share = (int) floor($amount * $material / max(1, $capacity['material']));

            if ($share > 0) {
                $taken['materials'][$name] = $share;
                $kept[$name] = $amount - $share;
            }
        }

        $faction->forceFill(['treasury' => $faction->treasury - $taken['corn'], 'materials' => $kept])->save();

        return $taken;
    }
}
