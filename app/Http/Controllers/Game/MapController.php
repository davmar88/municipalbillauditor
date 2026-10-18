<?php

namespace App\Http\Controllers\Game;

use App\Enums\BuildingKind;
use App\Game\Bases;
use App\Game\Clock;
use App\Game\GateNight;
use App\Game\Gathering;
use App\Game\Movement;
use App\Game\Wall;
use App\Http\Controllers\Controller;
use App\Models\Attack;
use App\Models\BattleCommitment;
use App\Models\GateDefence;
use App\Models\Law;
use App\Models\Player;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The valley map: who holds what, what is marching where, and gathering. */
class MapController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $valley = $player->valley;
        $season = $valley->currentSeason();
        $house = $valley->house();

        $regions = $valley->regions()->with(['faction:id,name,colour', 'neighbours:id', 'building'])->orderBy('row')->orderBy('col')->get();
        $open = $season?->attacks()->with(['faction:id,name,colour'])->whereNull('resolved_at')->get() ?? collect();
        $mine = $open->filter(fn (Attack $attack) => $player->faction_id !== null
            && ($attack->faction_id === $player->faction_id || $regions->firstWhere('id', $attack->region_id)?->faction_id === $player->faction_id));
        $committed = BattleCommitment::query()->where('player_id', $player->id)->whereIn('attack_id', $open->pluck('id'))->pluck('attack_id')->all();
        $paddock = $regions->firstWhere('key', 'paddock');

        // Who stands where. Animals without a region are in the Paddock.
        $animals = Player::query()
            ->with('faction:id,colour')
            ->where('valley_id', $valley->id)
            ->where('last_visit_on', '>=', Clock::now()->subDays(7)->toDateString())
            ->get(['id', 'name', 'species', 'faction_id', 'region_id'])
            ->groupBy(fn (Player $animal) => $animal->region_id ?? $paddock?->id);

        return Inertia::render('game/map', [
            'valley' => $valley->name,
            'season' => $season ? [
                'number' => $season->number,
                'endsAt' => $season->ends_at->toIso8601String(),
                'unrest' => Wall::unrest($season),
            ] : null,
            'house' => $house ? ['id' => $house->id, 'name' => $house->name, 'colour' => $house->colour] : null,
            'regions' => $regions->map(fn (Region $region) => [
                'id' => $region->id,
                'key' => $region->key,
                'name' => $region->name,
                'ring' => $region->ring,
                'produces' => $region->produces,
                'col' => $region->col,
                'row' => $region->row,
                'faction' => $region->faction ? ['id' => $region->faction->id, 'name' => $region->faction->name, 'colour' => $region->faction->colour] : null,
                'settledUntil' => $region->settled_until?->isFuture() ? $region->settled_until->toIso8601String() : null,
                'neighbours' => $region->neighbours->pluck('id'),
                'incoming' => $open->where('region_id', $region->id)->values()->map(fn (Attack $attack) => [
                    'id' => $attack->id,
                    'faction' => ['name' => $attack->faction->name, 'colour' => $attack->faction->colour],
                    'landsAt' => $attack->lands_at->toIso8601String(),
                    // Where the arrow on the map starts: land the attackers hold next door, or the Paddock.
                    'from' => $regions->first(fn (Region $other) => $region->neighbours->contains('id', $other->id) && $other->faction_id === $attack->faction_id)?->id
                        ?? ($region->neighbours->contains('id', $paddock?->id) ? $paddock?->id : null),
                ]),
                // A building stands only while its faction holds the land.
                'building' => $region->building && $region->building->faction_id === $region->faction_id
                    ? $region->building->summary()
                    : null,
                'animals' => [
                    'count' => ($animals->get($region->id) ?? collect())->count(),
                    'sample' => ($animals->get($region->id) ?? collect())->take(12)->map(fn (Player $animal) => [
                        'id' => $animal->id,
                        'name' => $animal->name,
                        'species' => $animal->species->value,
                        'colour' => $animal->faction?->colour,
                        'me' => $animal->id === $player->id,
                    ])->values(),
                ],
            ]),
            'here' => $player->region_id ?? $paddock?->id,
            'base' => $player->faction ? [
                'canBuild' => $player->decides(),
                'hasDen' => Bases::den($player->faction) !== null,
                'treasury' => $player->faction->treasury,
                'materials' => (object) ($player->faction->materials ?? []),
            ] : null,
            'buildingKinds' => array_map(fn (BuildingKind $kind) => $kind->card(), BuildingKind::cases()),
            'laws' => $season ? Wall::laws($season)->map(fn (Law $law) => [
                'label' => $law->template->label(),
                'wording' => $law->wording(),
            ])->values() : [],
            'battles' => $mine->values()->map(fn (Attack $attack) => [
                'id' => $attack->id,
                'region' => $regions->firstWhere('id', $attack->region_id)?->name,
                'attacker' => $attack->faction->name,
                'side' => $attack->faction_id === $player->faction_id ? 'attack' : 'defence',
                'landsAt' => $attack->lands_at->toIso8601String(),
                // Each side sees only its own strength.
                'ourHorseshoes' => (int) $attack->commitments()->where('side', $attack->faction_id === $player->faction_id ? 'attack' : 'defence')->sum('horseshoes'),
                'joined' => in_array($attack->id, $committed, true),
            ]),
            'recent' => $season?->attacks()->with(['faction:id,name', 'region:id,name', 'defender:id,name'])
                ->whereNotNull('resolved_at')->latest('resolved_at')->limit(8)->get()
                ->map(fn (Attack $attack) => [
                    'id' => $attack->id,
                    'text' => $attack->winner === 'attack'
                        ? "{$attack->faction->name} took {$attack->region->name}".($attack->defender ? " from {$attack->defender->name}" : '').'.'
                        : ($attack->defender?->name ?? 'The defenders')." held {$attack->region->name} against {$attack->faction->name}.",
                    'at' => $attack->resolved_at->toIso8601String(),
                ]) ?? [],
            'gate' => [
                'open' => GateNight::isGateDay(),
                'defended' => $season !== null && GateDefence::query()->where('season_id', $season->id)->where('player_id', $player->id)->where('night_on', Clock::today())->exists(),
            ],
            'yields' => ['corn' => Gathering::CORN, 'material' => Gathering::MATERIAL],
        ]);
    }

    public function walk(Request $request, Region $region, Movement $movement): RedirectResponse
    {
        $player = $request->user()->player;
        abort_unless($region->valley_id === $player->valley_id, 404);

        $movement->walk($player, $region);

        return $this->told("You walked to {$region->name}.");
    }

    public function gather(Request $request, Region $region, Gathering $gathering): RedirectResponse
    {
        $player = $request->user()->player;
        abort_unless($region->valley_id === $player->valley_id, 404);

        $result = $gathering->gather($player, $region);
        $what = $result['what'] === 'corn' ? 'Corn' : $result['what'];
        $tax = $result['tax'] > 0 ? " The House took {$result['tax']} in grain tax." : '';

        return $this->told("You gathered {$result['amount']} {$what} in {$region->name}.{$tax}");
    }
}
