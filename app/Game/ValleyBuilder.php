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
