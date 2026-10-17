<?php

namespace App\Game;

use App\Models\Faction;
use App\Models\Player;
use App\Models\Region;
use App\Models\Season;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The end of a season: the map, the laws and the anger reset; treasuries
 * keep a tenth and players a fifth of their Corn. Levels, Rosettes, items
 * and shops carry on.
 */
final class Seasons
{
    public function __construct(private readonly ValleyBuilder $builder) {}

    public function endIfDue(Valley $valley): ?Season
    {
        $season = $valley->currentSeason();

        if ($season === null || $season->ends_at->isFuture()) {
            return null;
        }

        return $this->end($valley, $season);
    }

    public function end(Valley $valley, Season $season): Season
    {
        return DB::transaction(function () use ($valley, $season) {
            $now = CarbonImmutable::now();

            $season->attacks()->whereNull('resolved_at')->update(['resolved_at' => $now]);
            $season->crimes()->whereNull('resolved_at')->update(['resolved_at' => $now]);
            $season->uprisings()->whereIn('status', ['gathering', 'open'])->update(['status' => 'failed', 'resolved_at' => $now]);
            $season->laws()->delete();
            $season->forceFill(['ended_at' => $now])->save();

            Region::query()->where('valley_id', $valley->id)->update(['faction_id' => null, 'held_since' => null, 'settled_until' => null]);
            Faction::query()->where('valley_id', $valley->id)->update(['treasury' => DB::raw('FLOOR(treasury / 10)')]);
            Player::query()->where('valley_id', $valley->id)->update(['corn' => DB::raw('FLOOR(corn / 5)')]);

            return $this->builder->startSeason($valley, $season->number + 1);
        });
    }
}
