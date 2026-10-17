<?php

namespace App\Game;

use App\Models\Region;
use App\Models\Season;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lays out a valley: the sixteen regions of Hollowmere on a five by three
 * grid with the Paddock along the bottom, which regions border which, and
 * the first season. Running it again changes nothing.
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
    ];

    public function build(string $name = 'Hollowmere'): Valley
    {
        return DB::transaction(function () use ($name) {
            $valley = Valley::query()->firstOrCreate(['slug' => str($name)->slug()->toString()], ['name' => $name]);

            foreach (self::REGIONS as $key => [$regionName, $ring, $produces, $col, $row]) {
                Region::query()->firstOrCreate(
                    ['valley_id' => $valley->id, 'key' => $key],
                    ['name' => $regionName, 'ring' => $ring, 'produces' => $produces, 'col' => $col, 'row' => $row],
                );
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
        if ($a->isSafe() || $b->isSafe()) {
            $other = $a->isSafe() ? $b : $a;

            return $other->row === 2;
        }

        return abs($a->col - $b->col) + abs($a->row - $b->row) === 1;
    }
}
