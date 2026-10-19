<?php

namespace App\Http\Controllers\Game;

use App\Enums\BuildingKind;
use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Game\Bases;
use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Farms;
use App\Game\GateNight;
use App\Game\Gathering;
use App\Game\Geography;
use App\Game\Homes;
use App\Game\Ironfield;
use App\Game\Irongate;
use App\Game\Movement;
use App\Game\Powers;
use App\Game\Stock;
use App\Game\ValleyBuilder;
use App\Game\Wall;
use App\Game\Wardrobe;
use App\Http\Controllers\Controller;
use App\Models\Attack;
use App\Models\BattleCommitment;
use App\Models\GateDefence;
use App\Models\Home;
use App\Models\HomeHelp;
use App\Models\Law;
use App\Models\Player;
use App\Models\Region;
use App\Models\Warrant;
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
        // Walks that ended since anyone looked put their animals where they arrived.
        Movement::settleArrived($valley->id);
        Movement::settle($player);
        $season = $valley->currentSeason();
        $house = $valley->house();

        // Wild land round the farms isn't a region yet: it stays off the map and its paths.
        // The scrub between them is everywhere and nowhere: it has its own card, not a shape.
        $regions = $valley->regions()
            ->where('wild', false)
            ->where('ring', '!=', 'scrub')
            ->with(['faction:id,name,colour', 'neighbours' => fn ($query) => $query->where('regions.wild', false), 'building'])
            ->orderBy('row')
            ->orderBy('col')
            ->get();
        $open = $season?->attacks()->with(['faction:id,name,colour'])->whereNull('resolved_at')->get() ?? collect();
        $mine = $open->filter(fn (Attack $attack) => $player->faction_id !== null
            && ($attack->faction_id === $player->faction_id || $regions->firstWhere('id', $attack->region_id)?->faction_id === $player->faction_id));
        $committed = BattleCommitment::query()->where('player_id', $player->id)->whereIn('attack_id', $open->pluck('id'))->pluck('attack_id')->all();
        $paddock = $regions->firstWhere('key', 'paddock');

        // Who stands where. Animals without a region are in the Paddock. You come
        // first, so the crowd count never counts you, then outlaws, so the map
        // can always show who is wanted.
        $wanted = $season ? Warrant::query()->where('season_id', $season->id)->where('status', 'open')->pluck('player_id')->all() : [];
        $animals = Player::query()
            ->with('faction:id,colour')
            ->where('valley_id', $valley->id)
            ->where('last_visit_on', '>=', Clock::now()->subDays(7)->toDateString())
            ->get(['id', 'name', 'species', 'faction_id', 'region_id', 'inside_home_at'])
            ->sortBy(fn (Player $animal) => match (true) {
                $animal->id === $player->id => 0,
                in_array($animal->id, $wanted, true) => 1,
                default => 2,
            })
            ->groupBy(fn (Player $animal) => $animal->region_id ?? $paddock?->id);
        // Outlaws lying low in Irongate show only to animals in the same district, or a Dog in the town.
        $hereRegion = $regions->firstWhere('id', $player->region_id ?? $paddock?->id) ?? $paddock;
        $animals = $animals->map(function ($group, $regionId) use ($regions, $player, $hereRegion, $wanted) {
            $where = $regions->firstWhere('id', $regionId);

            // Animals hiding in their homes don't show at all, except to themselves.
            $group = $group->reject(fn (Player $animal) => $animal->inside_home_at !== null && $animal->id !== $player->id);

            return $where === null || $hereRegion === null
                ? $group->values()
                : $group->reject(fn (Player $animal) => Irongate::hidden($animal->id, $where, $player, $hereRegion, $wanted))->values();
        });
        // What the animals the map shows are wearing, in one query.
        $wearing = Wardrobe::wornBy([$player->id, ...$animals->flatMap(fn ($group) => $group->take(12)->pluck('id'))]);

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
                'stock' => Stock::map($region),
                // How rich its land is: full near the farms, thinner further out.
                'richness' => Geography::regionRichness($region->key),
                // Ironfield's mission here, for this animal: how deep, who guards it, what it pays and when it's open again.
                'ironfield' => ($depth = Ironfield::depth($region)) === null ? null : [
                    'depth' => $depth,
                    'men' => Ironfield::MEN[$depth],
                    ...Ironfield::MISSIONS[$region->key],
                    'reward' => Ironfield::REWARD[$depth],
                    'poundHours' => $depth,
                    'nextAt' => Ironfield::nextAt($player, $region)?->toIso8601String(),
                    'odds' => array_map(fn (int $shoes) => Ironfield::chance($player, $depth, $shoes), range(1, Ironfield::MAX_HORSESHOES)),
                ],
                'col' => $region->col,
                'row' => $region->row,
                'faction' => $region->faction ? ['id' => $region->faction->id, 'name' => $region->faction->name, 'colour' => $region->faction->colour] : null,
                'settledUntil' => $region->settled_until?->isFuture() ? $region->settled_until->toIso8601String() : null,
                // Farms need water: until when this one is watered, or null where it doesn't matter.
                'wateredUntil' => $region->isFarm() && ! $region->wild ? ($region->watered_until?->isFuture() ? $region->watered_until->toIso8601String() : '') : null,
                // The regions its trench runs through, from the water to the farm.
                'trench' => $region->trench === null ? null : [
                    'keys' => $region->trench,
                    'from' => $regions->firstWhere('key', $region->trench[0])?->name ?? 'the water',
                ],
                // Where a trench to this farm would run from, and what it would cost, for the faction holding it.
                'trenchPlan' => $region->isFarm() && ! $region->wild && $region->trench === null && $player->faction_id !== null && $region->faction_id === $player->faction_id
                    ? (function () use ($region, $regions) {
                        $path = Farms::trenchPath($region);

                        return $path === null ? ['from' => null, 'stretches' => 0, 'cost' => null] : [
                            'from' => $regions->firstWhere('key', $path[0])?->name ?? 'the water',
                            'stretches' => count($path) - 1,
                            'cost' => Farms::trenchCost(count($path) - 1),
                        ];
                    })()
                    : null,
                'irrigated' => $region->isFarm() && ! $region->wild && ($region->trench !== null || $regions->contains(fn (Region $farm) => $farm->trench !== null && $farm->key === (ValleyBuilder::FIELD_OF[$region->key] ?? null))),
                'gatedUntil' => Powers::gatedUntil($region)?->toIso8601String(),
                'partOf' => isset(ValleyBuilder::FIELD_OF[$region->key]) ? $regions->firstWhere('key', ValleyBuilder::FIELD_OF[$region->key])?->name : null,
                'growth' => Farms::fieldKeys($region->key) === [] ? null : (function () use ($region) {
                    $next = Farms::nextField($region);
                    $grown = Farms::grown($region);

                    return [
                        'grown' => $grown,
                        'max' => Farms::GROWTH,
                        'next' => $next ? ['name' => $next->name, 'cost' => Farms::growCost($grown + 1, (string) $region->produces)] : null,
                    ];
                })(),
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
                    ? $region->building->summary($player)
                    : null,
                'animals' => [
                    'count' => ($animals->get($region->id) ?? collect())->count(),
                    'sample' => ($animals->get($region->id) ?? collect())->take(12)->map(fn (Player $animal) => [
                        'id' => $animal->id,
                        'name' => $animal->name,
                        'species' => $animal->species->value,
                        'colour' => $animal->faction?->colour,
                        'me' => $animal->id === $player->id,
                        'wearing' => $wearing[$animal->id] ?? [],
                    ])->values(),
                ],
            ]),
            'here' => $player->region_id ?? $paddock?->id,
            // Out in the open scrub: what there is to gather where you stand.
            'scrub' => (function () use ($player, $valley) {
                $scrub = $valley->regions()->where('ring', 'scrub')->first();

                if ($scrub === null || $player->region_id !== $scrub->id) {
                    return null;
                }

                [$x, $y] = Movement::position($player);

                return ['id' => $scrub->id, 'produces' => $scrub->produces, 'stock' => Stock::map($scrub), 'richness' => Geography::richness($x, $y)];
            })(),
            'base' => $player->faction ? [
                'canBuild' => $player->decides(),
                'hasDen' => Bases::den($player->faction) !== null,
                'treasury' => $player->faction->treasury,
                'materials' => (object) ($player->faction->materials ?? []),
            ] : null,
            'farms' => ['price' => Farms::PRICE, 'income' => Farms::INCOME, 'water' => Farms::WATER, 'waterHours' => Farms::WATER_HOURS, 'fill' => Farms::FILL],
            // Open warrants, so the map can mark outlaws and offer arrests.
            'warrants' => $season ? Warrant::query()
                ->with('wanted:id,name')
                ->where('season_id', $season->id)
                ->where('status', 'open')
                ->get()
                ->map(fn (Warrant $warrant) => [
                    'id' => $warrant->id,
                    'playerId' => $warrant->player_id,
                    'name' => $warrant->wanted->name,
                    'bounty' => $warrant->bounty,
                    'escapedUntil' => $warrant->escaped_until?->isFuture() ? $warrant->escaped_until->toIso8601String() : null,
                ]) : [],
            'plunder' => ['crop' => Crimes::PLUNDER_CROP, 'corn' => Crimes::PLUNDER_CORN, 'level' => (int) config('game.levels.crime'), 'horseshoes' => CrimeKind::Plunder->horseshoes()],
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
            'me' => Movement::map($player),
            // What your own animal wears, for the 3D map.
            'wearing' => $wearing[$player->id] ?? [],
            'gate' => [
                'open' => GateNight::isGateDay(),
                // Tonight's raid, so the map can ask how it went once it has happened.
                'at' => Clock::now()->setTime(GateNight::HOUR, 0)->toIso8601String(),
                'defended' => $season !== null && GateDefence::query()->where('season_id', $season->id)->where('player_id', $player->id)->where('night_on', Clock::today())->exists(),
                ...($season !== null && GateNight::isGateDay() ? GateNight::tonight($season) : ['defenders' => 0, 'strength' => 0, 'humans' => 0]),
                'result' => $season !== null ? GateNight::result($season) : null,
            ],
            'yields' => ['corn' => Gathering::CORN, 'material' => Gathering::MATERIAL],
            // Every animal's home, and yours with what building it further takes.
            'homes' => Home::query()
                ->with(['owner:id,name,species,faction_id', 'skin.design', 'skin.openListing'])
                ->whereHas('owner', fn ($query) => $query->where('valley_id', $valley->id))
                ->get()
                ->map(fn (Home $home) => [
                    'id' => $home->id,
                    'owner' => ['id' => $home->owner->id, 'name' => $home->owner->name, 'species' => $home->owner->species->value],
                    'x' => $home->x,
                    'y' => $home->y,
                    'level' => $home->level,
                    'name' => Homes::name($home->level),
                    'readyAt' => $home->building() ? $home->ready_at->toIso8601String() : null,
                    'skin' => Homes::skin($home),
                    'mine' => $home->player_id === $player->id,
                    // A faction mate's home going up that you could lend a hand on.
                    'helpable' => $home->player_id !== $player->id && $player->faction_id !== null && $home->owner->faction_id === $player->faction_id && $home->building()
                        && ! HomeHelp::query()->where('home_id', $home->id)->where('level', $home->level)->where('player_id', $player->id)->exists(),
                ])->values(),
            'home' => [
                'inside' => $player->inside_home_at !== null,
                // The levels so far and the next one: homes grow without end.
                'levels' => collect(range(1, max(3, (int) (Homes::of($player)?->level ?? 0) + 1)))->map(fn (int $n) => ['level' => $n, ...Homes::level($n)])->values(),
                'door' => Homes::DOOR,
                'skins' => $player->items()->with('design')->whereDoesntHave('openListing')->get()
                    ->filter(fn ($item) => $item->design->template === ItemTemplate::Skin)
                    ->map(fn ($item) => ['id' => $item->id, 'name' => $item->design->name, 'colours' => $item->design->colours, 'pattern' => $item->design->pattern])
                    ->values(),
            ],
            // Irongate's people and their prices, for the city's cards.
            'irongate' => [
                'folk' => collect(Irongate::FOLK)->map(fn (array $folk, string $key) => ['key' => $key, ...$folk])->values(),
                'muster' => Irongate::muster($valley),
                'fence' => [
                    'price' => Irongate::FENCE_PRICE,
                    'rarePrice' => Irongate::FENCE_RARE_PRICE,
                    'rare' => Irongate::RARE,
                    'left' => Irongate::fenceLeft($player),
                    'daily' => Irongate::FENCE_DAILY_CORN,
                ],
                'tip' => Irongate::TIP_CORN,
                'smith' => ['stone' => Irongate::SMITH_STONE, 'wood' => Irongate::SMITH_WOOD, 'daily' => Irongate::SMITH_DAILY],
                'meal' => Irongate::MEAL_CORN,
            ],
        ]);
    }

    public function walk(Request $request, Region $region, Movement $movement): RedirectResponse
    {
        $player = $request->user()->player;
        abort_unless($region->valley_id === $player->valley_id, 404);

        $player = $movement->walk($player, $region);

        return $this->toldWalk($player);
    }

    /** Walk in a straight line to a point on the map. */
    public function walkTo(Request $request, Movement $movement): RedirectResponse
    {
        $data = $request->validate([
            'x' => ['required', 'numeric', 'between:-2000,3000'],
            'y' => ['required', 'numeric', 'between:-2000,3000'],
        ]);

        return $this->toldWalk($movement->walkTo($request->user()->player, (float) $data['x'], (float) $data['y']));
    }

    public function stop(Request $request, Movement $movement): RedirectResponse
    {
        $player = $movement->stop($request->user()->player);

        return $this->told("You stopped in {$player->location()->name}.");
    }

    private function toldWalk(Player $player): RedirectResponse
    {
        $walk = Movement::map($player)['walk'];

        return $walk === null
            ? $this->told("You're in {$player->location()->name}.")
            : $this->told('You set off'.($walk['region'] ? " for {$walk['region']}" : '').'. You arrive at '.Clock::local($player->walk_arrives_at)->format('H:i').'.');
    }

    public function buy(Request $request, Region $region, Farms $farms): RedirectResponse
    {
        abort_unless($region->valley_id === $request->user()->player->valley_id, 404);
        $farms->buy($request->user()->player, $region);

        return $this->told("{$region->name} is your faction's now. It pays ".Farms::INCOME.' Corn into the treasury every half hour.');
    }

    public function grow(Request $request, Region $region, Farms $farms): RedirectResponse
    {
        abort_unless($region->valley_id === $request->user()->player->valley_id, 404);
        $field = $farms->grow($request->user()->player, $region);

        return $this->told("{$region->name} grew into {$field->name}. It's your faction's new region.");
    }

    public function water(Request $request, Region $region, Farms $farms): RedirectResponse
    {
        abort_unless($region->valley_id === $request->user()->player->valley_id, 404);
        $farm = $farms->water($request->user()->player, $region);

        return $this->told("{$region->name} is watered until ".Clock::local($farm->watered_until)->format('D H:i').'.');
    }

    public function ironfield(Request $request, Region $region, Ironfield $ironfield): RedirectResponse
    {
        abort_unless($region->valley_id === $request->user()->player->valley_id, 404);
        $data = $request->validate(['horseshoes' => ['required', 'integer', 'min:1', 'max:'.Ironfield::MAX_HORSESHOES]]);
        $result = $ironfield->run($request->user()->player, $region, (int) $data['horseshoes']);

        if (! $result['won']) {
            return $this->told("Ironfield's men caught you at {$region->name} and dragged you to the pound until ".Clock::local($result['poundUntil'])->format('H:i').'.');
        }

        $loot = collect($result['reward']['materials'])->map(fn (int $amount, string $material) => "{$amount} {$material}")->implode(', ');

        return $this->told("You got away from {$region->name} with {$result['reward']['corn']} Corn, {$loot} and {$result['reward']['rosettes']} Rosettes.");
    }

    public function dig(Request $request, Region $region, Farms $farms): RedirectResponse
    {
        abort_unless($region->valley_id === $request->user()->player->valley_id, 404);
        $farms->dig($request->user()->player, $region);

        return $this->told("Water runs to {$region->name} now. It won't run dry while the trench holds.");
    }

    public function fill(Request $request, Region $region, Farms $farms): RedirectResponse
    {
        abort_unless($region->valley_id === $request->user()->player->valley_id, 404);
        $farms->fill($request->user()->player, $region);

        return $this->told("You filled in the trench to {$region->name}.");
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
