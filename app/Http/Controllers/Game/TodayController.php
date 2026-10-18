<?php

namespace App\Http\Controllers\Game;

use App\Game\Clock;
use App\Game\GateNight;
use App\Game\Missions;
use App\Game\Skills;
use App\Http\Controllers\Controller;
use App\Models\Attack;
use App\Models\FactionJoinRequest;
use App\Models\GateDefence;
use App\Models\Listing;
use App\Models\Player;
use App\Models\PlayerMission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Today: your story, your jobs for the day, your shop, and what needs you. */
class TodayController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $season = $player->valley->currentSeason();
        $since = Clock::startOfToday();

        $sold = Listing::query()
            ->with('item.design:id,name')
            ->where('player_id', $player->id)
            ->whereNotNull('sold_at')
            ->latest('sold_at')
            ->limit(5)
            ->get();

        $battles = $season && $player->faction_id
            ? Attack::query()
                ->where('season_id', $season->id)
                ->whereNull('resolved_at')
                ->where(fn ($query) => $query
                    ->where('faction_id', $player->faction_id)
                    ->orWhereHas('region', fn ($region) => $region->where('faction_id', $player->faction_id)))
                ->count()
            : 0;

        return Inertia::render('game/today', [
            'animal' => [
                'name' => $player->name,
                'species' => $player->species->value,
                'speciesLabel' => $player->species->label(),
                'role' => $player->species->role(),
                'standingIn' => $player->location()->name,
            ],
            'season' => $season ? ['number' => $season->number, 'endsAt' => $season->ends_at->toIso8601String()] : null,
            'missions' => Missions::board($player),
            'skills' => array_map(fn (string $skill) => Skills::card($player, $skill), Skills::ALL),
            'shop' => [
                'shelves' => Listing::query()->where('player_id', $player->id)->whereNull('sold_at')->count(),
                'soldToday' => (int) Listing::query()->where('player_id', $player->id)->where('sold_at', '>=', $since)->count(),
                'cornToday' => (int) Listing::query()->where('player_id', $player->id)->where('sold_at', '>=', $since)->sum('price'),
                'recent' => $sold->map(fn (Listing $listing) => [
                    'id' => $listing->id,
                    'name' => $listing->item->design->name,
                    'price' => $listing->price,
                    'at' => $listing->sold_at->toIso8601String(),
                ]),
            ],
            'needs' => [
                'battles' => $battles,
                'requests' => $player->decides()
                    ? FactionJoinRequest::query()->where('faction_id', $player->faction_id)->where('status', 'pending')->count()
                    : 0,
                'gate' => $season !== null && GateNight::isGateDay()
                    && ! GateDefence::query()->where('season_id', $season->id)->where('player_id', $player->id)->where('night_on', Clock::today())->exists(),
            ],
        ]);
    }

    public function claim(Request $request, PlayerMission $mission): RedirectResponse
    {
        $reward = Missions::claim($request->user()->player, $mission);

        return $this->told('Collected '.self::describe($reward).'.');
    }

    /** A reward in words, like "30 Corn, 5 Rosettes and 2 horseshoes". */
    public static function describe(array $reward): string
    {
        $parts = [];

        if (! empty($reward['corn'])) {
            $parts[] = "{$reward['corn']} Corn";
        }

        if (! empty($reward['rosettes'])) {
            $parts[] = "{$reward['rosettes']} Rosettes";
        }

        if (! empty($reward['horseshoes'])) {
            $parts[] = $reward['horseshoes'].' '.($reward['horseshoes'] === 1 ? 'horseshoe' : 'horseshoes');
        }

        foreach ($reward['materials'] ?? [] as $material => $count) {
            $parts[] = "{$count} {$material}";
        }

        if ($parts === []) {
            return 'your reward';
        }

        return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)).' and '.end($parts) : $parts[0];
    }
}
