<?php

namespace App\Game;

use App\Models\Region;
use App\Models\Season;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lays out a valley: the sixteen regions of Hollowmere on a five by three
 * grid with the Paddock along the bottom, the farms beyond its fences,
 * Irongate's three districts past the east fence,
 * which regions border which, and the first season. Running it again adds
 * only what is missing, so a new farm reaches a running valley.
 */
final class ValleyBuilder
{
    /**
     * key => [name, ring, produces, column, row]. Regions that touch on the
     * grid border each other; the Paddock borders the whole bottom row.
     */
    public const REGIONS = [
        'wildwood' => ['Wildwood', 'outer', 'wood', 0, 0],
        'orchard' => ['Orchard', 'middle', 'dye', 1, 0],
        'big_barn' => ['Big Barn', 'inner', 'straw', 2, 0],
        'hayfield' => ['Hayfield', 'middle', 'corn', 3, 0],
        'quarry' => ['Quarry', 'outer', 'stone', 4, 0],
        'sheepfold' => ['Sheepfold', 'outer', 'wool', 0, 1],
        'granary' => ['Granary', 'inner', 'corn', 1, 1],
        'farmhouse' => ['The Farmhouse', 'seat', null, 2, 1],
        'windmill_hill' => ['Windmill Hill', 'inner', 'corn', 3, 1],
        'ironfield_gate' => ['Ironfield Gate', 'outer', 'corn', 4, 1],
        'duck_pond' => ['Duck Pond', 'outer', 'clay', 0, 2],
        'henhouse' => ['Henhouse', 'middle', 'eggs', 1, 2],
        'market_square' => ['Market Square', 'inner', 'corn', 2, 2],
        'stables' => ['Stables', 'middle', 'straw', 3, 2],
        'old_well' => ['Old Well', 'outer', 'clay', 4, 2],
        'paddock' => ['The Paddock', 'safe', 'corn', 2, 3],
        // Farms beyond the fences: bought with Corn or taken in raids.
        // Each grows something found nowhere in Hollowmere.
        'thornbury' => ['Thornbury Farm', 'farm', 'honey', 0, 9],
        'brookside' => ['Brookside Farm', 'farm', 'herbs', 1, 9],
        'willowby' => ['Willowby Farm', 'farm', 'iron', 2, 9],
        // Wild land round each farm, cleared one patch at a time as its holder grows it.
        'thornbury_heath' => ['Thornbury Heath', 'farm', 'honey', 3, 9],
        'thornbury_hives' => ['Thornbury Hives', 'farm', 'honey', 4, 9],
        'thornbury_hollow' => ['Thornbury Hollow', 'farm', 'honey', 5, 9],
        'brookside_reeds' => ['Brookside Reeds', 'farm', 'herbs', 6, 9],
        'brookside_marsh' => ['Brookside Marsh', 'farm', 'herbs', 7, 9],
        'brookside_meadow' => ['Brookside Meadow', 'farm', 'herbs', 8, 9],
        'willowby_copse' => ['Willowby Copse', 'farm', 'iron', 9, 9],
        'willowby_forge' => ['Willowby Forge', 'farm', 'iron', 10, 9],
        'willowby_pits' => ['Willowby Pits', 'farm', 'iron', 11, 9],
        // The second ring of farms, out beyond the wild land round the first.
        'ashcombe' => ['Ashcombe Farm', 'farm', 'iron', 12, 9],
        'fennick' => ['Fennick Farm', 'farm', 'honey', 13, 9],
        'marlow' => ['Marlow Farm', 'farm', 'herbs', 14, 9],
        'ashcombe_ridge' => ['Ashcombe Ridge', 'farm', 'iron', 15, 9],
        'ashcombe_wood' => ['Ashcombe Wood', 'farm', 'iron', 16, 9],
        'ashcombe_spring' => ['Ashcombe Spring', 'farm', 'iron', 17, 9],
        'fennick_downs' => ['Fennick Downs', 'farm', 'honey', 18, 9],
        'fennick_moor' => ['Fennick Moor', 'farm', 'honey', 19, 9],
        'fennick_brake' => ['Fennick Brake', 'farm', 'honey', 20, 9],
        'marlow_glen' => ['Marlow Glen', 'farm', 'herbs', 21, 9],
        'marlow_leas' => ['Marlow Leas', 'farm', 'herbs', 22, 9],
        'marlow_mill' => ['Marlow Mill', 'farm', 'herbs', 23, 9],
        // The third ring, out at the edges of the valley, each with its wild land.
        'hartley' => ['Hartley Farm', 'farm', 'carrots', 24, 9],
        'northfold' => ['Northfold Farm', 'farm', 'pears', 25, 9],
        'coldharbour' => ['Coldharbour Farm', 'farm', 'gold', 26, 9],
        'rushmere' => ['Rushmere Farm', 'farm', 'berries', 27, 9],
        'southmere' => ['Southmere Farm', 'farm', 'pumpkins', 28, 9],
        'copperhill' => ['Copperhill Farm', 'farm', 'copper', 29, 9],
        'hartley_fold' => ['Hartley Fold', 'farm', 'carrots', 30, 9],
        'hartley_fen' => ['Hartley Fen', 'farm', 'carrots', 31, 9],
        'hartley_rise' => ['Hartley Rise', 'farm', 'carrots', 32, 9],
        'northfold_heath' => ['Northfold Heath', 'farm', 'pears', 33, 9],
        'northfold_hives' => ['Northfold Hives', 'farm', 'pears', 34, 9],
        'northfold_crag' => ['Northfold Crag', 'farm', 'pears', 35, 9],
        'coldharbour_scar' => ['Coldharbour Scar', 'farm', 'gold', 36, 9],
        'coldharbour_mine' => ['Coldharbour Mine', 'farm', 'gold', 37, 9],
        'coldharbour_moss' => ['Coldharbour Moss', 'farm', 'gold', 38, 9],
        'rushmere_bog' => ['Rushmere Bog', 'farm', 'berries', 39, 9],
        'rushmere_banks' => ['Rushmere Banks', 'farm', 'berries', 40, 9],
        'rushmere_carr' => ['Rushmere Carr', 'farm', 'berries', 41, 9],
        'southmere_dell' => ['Southmere Dell', 'farm', 'pumpkins', 42, 9],
        'southmere_gardens' => ['Southmere Gardens', 'farm', 'pumpkins', 43, 9],
        'southmere_weald' => ['Southmere Weald', 'farm', 'pumpkins', 44, 9],
        'copperhill_workings' => ['Copperhill Workings', 'farm', 'copper', 45, 9],
        'copperhill_tor' => ['Copperhill Tor', 'farm', 'copper', 46, 9],
        'copperhill_slag' => ['Copperhill Slag', 'farm', 'copper', 47, 9],
        // Irongate, the humans' town past Ironfield Gate: nobody holds it and nothing grows there.
        'irongate_rookery' => ['The Rookery', 'city', null, 0, 10],
        'irongate_yards' => ['The Iron Yards', 'city', null, 1, 10],
        'irongate_garrison' => ['The Garrison', 'city', null, 2, 10],
        // The frontier: wild land filling the valley round the farms, open to all.
        'glitter_gully' => ['Glitter Gully', 'frontier', 'gold', 0, 11],
        'blackthorn_wood' => ['Blackthorn Wood', 'frontier', 'wood', 1, 11],
        'mirror_ponds' => ['Mirror Ponds', 'frontier', 'clay', 2, 11],
        'bramble_heath' => ['Bramble Heath', 'frontier', 'dye', 3, 11],
        'grey_scree' => ['Grey Scree', 'frontier', 'stone', 4, 11],
        'long_meadow' => ['Long Meadow', 'frontier', 'wool', 5, 11],
        'rookhollow_wood' => ['Rookhollow Wood', 'frontier', 'wood', 6, 11],
        'heron_mere' => ['Heron Mere', 'frontier', 'clay', 7, 11],
        'gorse_common' => ['Gorse Common', 'frontier', 'dye', 8, 11],
        'flint_downs' => ['Flint Downs', 'frontier', 'stone', 9, 11],
        'sheep_down' => ['Sheep Down', 'frontier', 'wool', 10, 11],
        'gallows_copse' => ['Gallows Copse', 'frontier', 'wood', 11, 11],
        'silt_pools' => ['Silt Pools', 'frontier', 'clay', 12, 11],
        'thistle_down' => ['Thistle Down', 'frontier', 'dye', 13, 11],
        'cairn_hill' => ['Cairn Hill', 'frontier', 'stone', 14, 11],
        'old_copper_seam' => ['Old Copper Seam', 'frontier', 'copper', 15, 11],
        'cowslip_lea' => ['Cowslip Lea', 'frontier', 'wool', 16, 11],
        'ashwood' => ['Ashwood', 'frontier', 'wood', 17, 11],
        'eelwater' => ['Eelwater', 'frontier', 'clay', 18, 11],
        'wild_hives' => ['Wild Hives', 'frontier', 'honey', 19, 11],
        'briar_scrub' => ['Briar Scrub', 'frontier', 'dye', 20, 11],
        'rattle_rocks' => ['Rattle Rocks', 'frontier', 'stone', 21, 11],
        'windy_common' => ['Windy Common', 'frontier', 'wool', 22, 11],
        'hazel_hanger' => ['Hazel Hanger', 'frontier', 'wood', 23, 11],
        'berry_brake' => ['Berry Brake', 'frontier', 'berries', 24, 11],
    ];

    /** What each frontier region borders: its nearest land. */
    public const FRONTIER_LINKS = [
        'glitter_gully' => ['hartley_fen', 'blackthorn_wood'],
        'blackthorn_wood' => ['windy_common', 'hartley_fen'],
        'mirror_ponds' => ['long_meadow', 'northfold_crag'],
        'bramble_heath' => ['windy_common', 'hartley_rise'],
        'grey_scree' => ['northfold_crag', 'mirror_ponds'],
        'long_meadow' => ['mirror_ponds', 'coldharbour_mine'],
        'rookhollow_wood' => ['hartley_rise', 'bramble_heath'],
        'heron_mere' => ['rookhollow_wood', 'hartley'],
        'gorse_common' => ['hartley_fold', 'hartley'],
        'flint_downs' => ['fennick_moor', 'fennick_downs'],
        'sheep_down' => ['rushmere_bog', 'rushmere'],
        'gallows_copse' => ['hazel_hanger', 'brookside_meadow'],
        'silt_pools' => ['hazel_hanger', 'rushmere_carr'],
        'thistle_down' => ['marlow_mill', 'southmere_weald'],
        'cairn_hill' => ['copperhill_slag', 'eelwater'],
        'old_copper_seam' => ['rushmere_banks', 'wild_hives'],
        'cowslip_lea' => ['berry_brake', 'rushmere_banks'],
        'ashwood' => ['southmere_dell', 'silt_pools'],
        'eelwater' => ['southmere_weald', 'rattle_rocks'],
        'wild_hives' => ['old_copper_seam', 'berry_brake'],
        'briar_scrub' => ['northfold_heath', 'ashcombe_ridge'],
        'rattle_rocks' => ['eelwater', 'cairn_hill'],
        'windy_common' => ['bramble_heath', 'blackthorn_wood'],
        'hazel_hanger' => ['silt_pools', 'rushmere_carr'],
        'berry_brake' => ['cowslip_lea', 'wild_hives'],
    ];

    /** Irongate's districts and what each borders: the Yards open onto Ironfield Gate and the other two. */
    public const CITY_LINKS = [
        'irongate_yards' => ['ironfield_gate', 'irongate_rookery', 'irongate_garrison'],
    ];

    /** Which farm each patch of wild land belongs to, in the order it is cleared. */
    public const FIELD_OF = [
        'thornbury_heath' => 'thornbury',
        'thornbury_hives' => 'thornbury',
        'thornbury_hollow' => 'thornbury',
        'brookside_reeds' => 'brookside',
        'brookside_marsh' => 'brookside',
        'brookside_meadow' => 'brookside',
        'willowby_copse' => 'willowby',
        'willowby_forge' => 'willowby',
        'willowby_pits' => 'willowby',
        'ashcombe_ridge' => 'ashcombe',
        'ashcombe_wood' => 'ashcombe',
        'ashcombe_spring' => 'ashcombe',
        'fennick_downs' => 'fennick',
        'fennick_moor' => 'fennick',
        'fennick_brake' => 'fennick',
        'marlow_glen' => 'marlow',
        'marlow_leas' => 'marlow',
        'marlow_mill' => 'marlow',
        'hartley_fold' => 'hartley',
        'hartley_fen' => 'hartley',
        'hartley_rise' => 'hartley',
        'northfold_heath' => 'northfold',
        'northfold_hives' => 'northfold',
        'northfold_crag' => 'northfold',
        'coldharbour_scar' => 'coldharbour',
        'coldharbour_mine' => 'coldharbour',
        'coldharbour_moss' => 'coldharbour',
        'rushmere_bog' => 'rushmere',
        'rushmere_banks' => 'rushmere',
        'rushmere_carr' => 'rushmere',
        'southmere_dell' => 'southmere',
        'southmere_gardens' => 'southmere',
        'southmere_weald' => 'southmere',
        'copperhill_workings' => 'copperhill',
        'copperhill_tor' => 'copperhill',
        'copperhill_slag' => 'copperhill',
    ];

    /** Which Hollowmere regions each farm borders. */
    public const FARM_LINKS = [
        'thornbury' => ['orchard', 'big_barn', 'hayfield'],
        'brookside' => ['wildwood', 'sheepfold', 'duck_pond'],
        'willowby' => ['paddock'],
        'thornbury_heath' => ['thornbury'],
        'thornbury_hives' => ['thornbury'],
        'thornbury_hollow' => ['thornbury'],
        'brookside_reeds' => ['brookside'],
        'brookside_marsh' => ['brookside'],
        'brookside_meadow' => ['brookside'],
        'willowby_copse' => ['willowby'],
        'willowby_forge' => ['willowby'],
        'willowby_pits' => ['willowby'],
        // The second ring is reached from the first ring's farms.
        'ashcombe' => ['thornbury'],
        'fennick' => ['brookside'],
        'marlow' => ['willowby'],
        'ashcombe_ridge' => ['ashcombe'],
        'ashcombe_wood' => ['ashcombe'],
        'ashcombe_spring' => ['ashcombe'],
        'fennick_downs' => ['fennick'],
        'fennick_moor' => ['fennick'],
        'fennick_brake' => ['fennick'],
        'marlow_glen' => ['marlow'],
        'marlow_leas' => ['marlow'],
        'marlow_mill' => ['marlow'],
        // The third ring is reached from the second ring's farms.
        'hartley' => ['fennick'],
        'northfold' => ['ashcombe'],
        'coldharbour' => ['ashcombe'],
        'rushmere' => ['marlow'],
        'southmere' => ['marlow'],
        'copperhill' => ['willowby'],
        'hartley_fold' => ['hartley'],
        'hartley_fen' => ['hartley'],
        'hartley_rise' => ['hartley'],
        'northfold_heath' => ['northfold'],
        'northfold_hives' => ['northfold'],
        'northfold_crag' => ['northfold'],
        'coldharbour_scar' => ['coldharbour'],
        'coldharbour_mine' => ['coldharbour'],
        'coldharbour_moss' => ['coldharbour'],
        'rushmere_bog' => ['rushmere'],
        'rushmere_banks' => ['rushmere'],
        'rushmere_carr' => ['rushmere'],
        'southmere_dell' => ['southmere'],
        'southmere_gardens' => ['southmere'],
        'southmere_weald' => ['southmere'],
        'copperhill_workings' => ['copperhill'],
        'copperhill_tor' => ['copperhill'],
        'copperhill_slag' => ['copperhill'],
    ];

    public function build(string $name = 'Hollowmere'): Valley
    {
        return DB::transaction(function () use ($name) {
            $valley = Valley::query()->firstOrCreate(['slug' => str($name)->slug()->toString()], ['name' => $name]);

            foreach (self::REGIONS as $key => [$regionName, $ring, $produces, $col, $row]) {
                // Kept in step with the list, so a region's crop can change; who holds it never does here.
                $region = Region::query()->updateOrCreate(
                    ['valley_id' => $valley->id, 'key' => $key],
                    ['name' => $regionName, 'ring' => $ring, 'produces' => $produces, 'col' => $col, 'row' => $row],
                );

                // A farm's patches start wild; once cleared they stay cleared.
                if ($region->wasRecentlyCreated && isset(self::FIELD_OF[$key])) {
                    $region->forceFill(['wild' => true])->save();
                }
            }

            $this->link($valley);

            if ($valley->currentSeason() === null) {
                $this->startSeason($valley, 1);
            }

            return $valley;
        });
    }

    public function startSeason(Valley $valley, int $number): Season
    {
        $now = CarbonImmutable::now();

        return Season::create([
            'valley_id' => $valley->id,
            'number' => $number,
            'starts_at' => $now,
            'ends_at' => $now->addDays((int) config('game.season_days')),
        ]);
    }

    private function link(Valley $valley): void
    {
        $regions = $valley->regions()->get()->keyBy('key');
        $links = [];

        foreach ($regions as $region) {
            foreach ($regions as $other) {
                if ($region->is($other)) {
                    continue;
                }

                if ($this->touches($region, $other)) {
                    $links[] = ['region_id' => $region->id, 'neighbour_id' => $other->id];
                }
            }
        }

        DB::table('region_links')->insertOrIgnore($links);
    }

    private function touches(Region $a, Region $b): bool
    {
        if ($a->ring === 'frontier' || $b->ring === 'frontier') {
            return in_array($b->key, self::FRONTIER_LINKS[$a->key] ?? [], true)
                || in_array($a->key, self::FRONTIER_LINKS[$b->key] ?? [], true);
        }

        if ($a->isCity() || $b->isCity()) {
            return in_array($b->key, self::CITY_LINKS[$a->key] ?? [], true)
                || in_array($a->key, self::CITY_LINKS[$b->key] ?? [], true);
        }

        if ($a->isFarm() || $b->isFarm()) {
            return in_array($b->key, self::FARM_LINKS[$a->key] ?? [], true)
                || in_array($a->key, self::FARM_LINKS[$b->key] ?? [], true);
        }

        if ($a->isSafe() || $b->isSafe()) {
            $other = $a->isSafe() ? $b : $a;

            return $other->row === 2;
        }

        return abs($a->col - $b->col) + abs($a->row - $b->row) === 1;
    }
}
