<?php

namespace App\Http\Controllers\Game;

use App\Enums\BuildingKind;
use App\Enums\CrimeKind;
use App\Enums\ItemTemplate;
use App\Game\Banners;
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
use App\Game\Plunderers;
use App\Game\Powers;
use App\Game\RaidRules;
use App\Game\Stock;
use App\Game\Survival;
use App\Game\ValleyBuilder;
use App\Game\Wall;
use App\Game\Wardrobe;
use App\Game\Weather;
use App\Http\Controllers\Controller;
use App\Models\Attack;
use App\Models\BattleCommitment;
use App\Models\Crime;
use App\Models\Faction;
use App\Models\GateDefence;
use App\Models\Home;
use App\Models\HomeHelp;
use App\Models\Law;
use App\Models\LootDrop;
use App\Models\Player;
use App\Models\PlunderBand;
use App\Models\Region;
use App\Models\Season;
use App\Models\Warrant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        app(Homes::class)->buildArrivedCamps($valley->id);
        Movement::settle($player);
        $season = $valley->currentSeason();

        // A robbery or a burning you made, or one aimed at you, happens the moment the robber arrives.
        if ($season !== null) {
            $season->crimes()
                ->whereNull('resolved_at')
                ->whereNotNull('lands_at')
                ->where('settles_at', '<=', now())
                ->where(fn ($query) => $query->where('player_id', $player->id)->orWhere('target_player_id', $player->id))
                ->get()
                ->each(fn (Crime $crime) => app(Crimes::class)->resolve($season, $crime));
        }
        $house = $valley->house();

        // Wild land round the farms isn't a region yet: it stays off the map and its paths.
        // The scrub between them is everywhere and nowhere: it has its own card, not a shape.
        $regions = $valley->regions()
            ->where('wild', false)
            ->where('ring', '!=', 'scrub')
            ->with(['faction:id,name,colour,banner,banner_path', 'neighbours' => fn ($query) => $query->where('regions.wild', false), 'building'])
            ->orderBy('row')
            ->orderBy('col')
            ->get();
        $open = $season?->attacks()->with(['faction:id,name,colour'])->whereNull('resolved_at')->get() ?? collect();
        $mine = $open->filter(fn (Attack $attack) => $player->faction_id !== null
            && ($attack->faction_id === $player->faction_id || $regions->firstWhere('id', $attack->region_id)?->faction_id === $player->faction_id));
        $committed = BattleCommitment::query()->where('player_id', $player->id)->whereIn('attack_id', $open->pluck('id'))->pluck('attack_id')->all();
        $paddock = $regions->firstWhere('key', 'paddock');
        $band = $season !== null ? Plunderers::riding($season->id) : null;

        // Who stands where. Animals without a region are in the Paddock. You come
        // first, so the crowd count never counts you, then outlaws, so the map
        // can always show who is wanted.
        $wanted = $season ? Warrant::query()->where('season_id', $season->id)->where('status', 'open')->pluck('player_id')->all() : [];
        // Read plainly, not as models: a busy valley has thousands of animals.
        $colours = Faction::query()->where('valley_id', $valley->id)->pluck('colour', 'id');
        // Each faction's stored water, which its members drink from: for the health bars over them.
        $stores = Faction::query()->where('valley_id', $valley->id)->pluck('materials', 'id')
            ->map(fn ($materials) => (int) ((is_string($materials) ? json_decode($materials, true) : $materials)['water'] ?? 0));
        // Land on fire from a plunder or a raid, and the ashes after.
        $scorched = $season ? $this->scorched($season) : [];
        // Every animal stays on the map, online or not: offline ones can still be robbed, their homes burned.
        // Animals hiding in their homes don't show at all, except to themselves.
        $visible = fn ($query) => $query->where('valley_id', $valley->id)
            ->where(fn ($query) => $query->whereNull('inside_home_at')->orWhere('id', $player->id));
        $paddockId = (int) ($paddock?->id ?? 0);
        // How many stand in each region, counted by the database.
        $counts = Player::query()->toBase()->tap($visible)
            ->selectRaw('COALESCE(region_id, ?) AS region, COUNT(*) AS total', [$paddockId])
            ->groupBy('region')
            ->pluck('total', 'region');
        // A few from each region to draw: you first, then the wanted, then whoever was about last.
        $first = implode(',', array_map('intval', $wanted ?: [0]));
        $animals = DB::query()
            ->fromSub(
                Player::query()->toBase()->tap($visible)
                    ->select(['id', 'name', 'species', 'faction_id', 'region_id', 'inside_home_at', 'food', 'drink', 'health', 'vitals_at', 'corn', 'materials', 'pos_x', 'pos_y', 'walk_to_x', 'walk_to_y', 'walk_arrives_at', 'npc'])
                    ->selectRaw("ROW_NUMBER() OVER (PARTITION BY COALESCE(region_id, ?) ORDER BY id = ? DESC, id IN ({$first}) DESC, last_seen_at DESC, id) AS place", [$paddockId, $player->id]),
                'ranked',
            )
            ->where('place', '<=', 30)
            ->orderBy('place')
            ->get()
            ->groupBy(fn (object $animal) => $animal->region_id ?? $paddock?->id);
        // Outlaws lying low in Irongate show only to animals in the same district, or a Dog in the town.
        $hereRegion = $regions->firstWhere('id', $player->region_id ?? $paddock?->id) ?? $paddock;
        $animals = $animals->map(function ($group, $regionId) use ($regions, $player, $hereRegion, $wanted, &$counts) {
            $where = $regions->firstWhere('id', $regionId);

            if ($where === null || $hereRegion === null) {
                return $group->values();
            }

            $shown = $group->reject(fn (object $animal) => Irongate::hidden($animal->id, $where, $player, $hereRegion, $wanted))->values();
            // Hidden outlaws don't count either: the wanted come early in each region's few, so they're all here.
            $counts[$regionId] = max(0, (int) ($counts[$regionId] ?? 0) - ($group->count() - $shown->count()));

            return $shown;
        });
        // What the animals the map shows are wearing, in one query.
        $wearing = Wardrobe::wornBy([$player->id, ...$animals->flatMap(fn ($group) => $group->take(24)->pluck('id'))]);

        return Inertia::render('game/map', [
            'valley' => $valley->name,
            'season' => $season ? [
                'number' => $season->number,
                'endsAt' => $season->ends_at->toIso8601String(),
                'unrest' => Wall::unrest($season),
            ] : null,
            'house' => $house ? ['id' => $house->id, 'name' => $house->name, 'colour' => $house->colour, 'banner' => Banners::of($house)] : null,
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
                'faction' => $region->faction ? ['id' => $region->faction->id, 'name' => $region->faction->name, 'colour' => $region->faction->colour, 'banner' => Banners::of($region->faction)] : null,
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
                'irrigated' => $region->isFarm() && ! $region->wild && ($region->trench !== null || $regions->contains(fn (Region $farm) => $farm->trench !== null && $farm->key === (ValleyBuilder::FIELD_OF[$region->key] ?? null)) || Bases::wellWaters($region)),
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
                // Irongate's plunderers riding for it.
                'plunderers' => $band?->region_id === $region->id ? ['men' => $band->men, 'landsAt' => $band->lands_at->toIso8601String()] : null,
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
                    'count' => (int) ($counts[$region->id] ?? 0),
                    'sample' => ($animals->get($region->id) ?? collect())->take(24)->map(fn (object $animal) => [
                        'id' => $animal->id,
                        // Run by the game itself, not a person.
                        'npc' => (bool) $animal->npc,
                        // Where it stands; one out walking is drawn on its trail.
                        'at' => match (true) {
                            $animal->walk_arrives_at === null => Movement::standing((int) $animal->id, $region->key, $animal->pos_x === null ? null : (float) $animal->pos_x, $animal->pos_y === null ? null : (float) $animal->pos_y),
                            CarbonImmutable::parse($animal->walk_arrives_at)->isPast() => [(float) $animal->walk_to_x, (float) $animal->walk_to_y],
                            default => null,
                        },
                        'name' => $animal->name,
                        'species' => $animal->species,
                        'colour' => $animal->faction_id !== null ? ($colours[$animal->faction_id] ?? null) : null,
                        'me' => $animal->id === $player->id,
                        'wearing' => $wearing[$animal->id] ?? [],
                        // Out of 100, for the bar over every animal.
                        'health' => Survival::healthOf($animal, $season, $animal->faction_id !== null ? ($stores[$animal->faction_id] ?? 0) : 0),
                    ])->values(),
                ],
                // Burning from a plunder or a raid, then ashes.
                'fire' => $scorched[$region->id] ?? null,
            ]),
            'here' => $player->region_id ?? $paddock?->id,
            // Robberies and burnings on their way, to you or from you: the red arrows.
            'strikes' => $season ? $season->crimes()
                ->with(['player:id,name', 'targetPlayer:id,name'])
                ->whereNull('resolved_at')
                ->whereNotNull('lands_at')
                ->where(fn ($query) => $query->where('player_id', $player->id)->orWhere('target_player_id', $player->id))
                ->get()
                ->map(fn (Crime $crime) => [
                    'id' => $crime->id,
                    'kind' => $crime->kind->value,
                    'mine' => $crime->player_id === $player->id,
                    'who' => $crime->player_id === $player->id ? $crime->targetPlayer?->name : $crime->player?->name,
                    'from' => [$crime->from_x, $crime->from_y],
                    'to' => [$crime->to_x, $crime->to_y],
                    'startedAt' => ($crime->path_started_at ?? $crime->created_at)->toIso8601String(),
                    'landsAt' => $crime->lands_at->toIso8601String(),
                ]) : [],
            // Everyone else out walking: where from, where to and when, so the map draws their trail as it does yours.
            'walkers' => Player::query()->toBase()
                ->where('valley_id', $valley->id)
                ->where('id', '!=', $player->id)
                ->where('walk_arrives_at', '>', now())
                ->whereNull('inside_home_at')
                ->orderBy('walk_arrives_at')
                ->limit(150)
                ->get(['id', 'name', 'species', 'faction_id', 'walk_from_x', 'walk_from_y', 'walk_to_x', 'walk_to_y', 'walk_started_at', 'walk_arrives_at'])
                ->map(fn (object $walker) => [
                    'id' => (int) $walker->id,
                    'name' => $walker->name,
                    'species' => $walker->species,
                    'colour' => $walker->faction_id !== null ? ($colours[$walker->faction_id] ?? null) : null,
                    'from' => [round((float) $walker->walk_from_x, 1), round((float) $walker->walk_from_y, 1)],
                    'to' => [round((float) $walker->walk_to_x, 1), round((float) $walker->walk_to_y, 1)],
                    'startedAt' => CarbonImmutable::parse($walker->walk_started_at)->toIso8601String(),
                    'arrivesAt' => CarbonImmutable::parse($walker->walk_arrives_at)->toIso8601String(),
                ]),
            // Spring, summer, fall or winter, and how far through the season we are.
            'weather' => Weather::forMap($season),
            // What dead animals left lying about, for anyone to pick up.
            'loot' => LootDrop::query()->lying()->where('valley_id', $valley->id)->with('player:id,name')->limit(200)->get()
                ->map(fn (LootDrop $drop) => [
                    'id' => $drop->id,
                    'x' => $drop->x,
                    'y' => $drop->y,
                    'corn' => $drop->corn,
                    'materials' => (object) ($drop->materials ?? []),
                    'from' => $drop->player?->name,
                    'expiresAt' => $drop->expires_at->toIso8601String(),
                ]),
            // The raid rules your faction holds you to, to warn before a raid breaks one; none when rogue or excused.
            'raidRules' => $player->faction && ! $player->faction->rogue && ! $player->rules_exempt
                ? [...RaidRules::of($player->faction), 'labels' => RaidRules::RULES]
                : null,
            // Out in the open scrub: what there is to gather where you stand.
            'scrub' => (function () use ($player, $valley) {
                $scrub = $valley->regions()->where('ring', 'scrub')->first();

                if ($scrub === null || $player->region_id !== $scrub->id) {
                    return null;
                }

                [$x, $y] = Movement::position($player);

                return ['id' => $scrub->id, 'produces' => $scrub->produces, 'stock' => null, 'richness' => Geography::richness($x, $y)];
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
            // Setting another animal's home alight, from its card on the map.
            'burn' => [
                'level' => (int) config('game.levels.crime'),
                'horseshoes' => CrimeKind::Burn->horseshoes(),
                'usedToday' => Crimes::triedToday($player),
            ],
            'plunder' => [
                'crop' => Crimes::PLUNDER_CROP,
                'corn' => Crimes::PLUNDER_CORN,
                'level' => (int) config('game.levels.crime'),
                'horseshoes' => CrimeKind::Plunder->horseshoes(),
                // Looting a faction's treasury, from any of its land.
                'lootHorseshoes' => CrimeKind::Loot->horseshoes(),
                'lootShare' => (int) round(Crimes::LOOT_SHARE * 100),
            ],
            'buildingKinds' => array_map(fn (BuildingKind $kind) => $kind->card(), BuildingKind::cases()),
            'laws' => $season ? Wall::laws($season)->filter(fn (Law $law) => Wall::canSee($player, $law))->map(fn (Law $law) => [
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
            // The band of Irongate's plunderers riding now, and whether you're fighting it.
            'plunderers' => $band === null ? null : [
                'id' => $band->id,
                'region' => $band->region->key,
                'regionName' => $band->region->name,
                'holders' => $band->faction?->name,
                'men' => $band->men,
                'landsAt' => $band->lands_at->toIso8601String(),
                'fighters' => $band->fighters()->count(),
                'strength' => round(Plunderers::strength($band), 1),
                'fighting' => $band->fighters()->where('player_id', $player->id)->exists(),
                'canFight' => $player->faction_id !== null,
                'corn' => Plunderers::CORN_PER_MAN * $band->men,
            ],
            'homes' => (function () use ($valley, $season, $player) {
                $homes = Homes::onMap($valley, $season);
                // Faction mates' homes going up that you've already helped with this level.
                $helped = $player->faction_id === null ? [] : HomeHelp::query()
                    ->where('player_id', $player->id)
                    ->whereIn('home_id', collect($homes)->filter(fn (array $home) => $home['readyAt'] !== null && $home['factionId'] === $player->faction_id)->pluck('id'))
                    ->get(['home_id', 'level'])
                    ->map(fn (HomeHelp $help) => "{$help->home_id}:{$help->level}")
                    ->flip()
                    ->all();

                return array_map(function (array $home) use ($player, $helped) {
                    $mine = $home['owner']['id'] === $player->id;
                    $home['mine'] = $mine;
                    $home['helpable'] = ! $mine && $player->faction_id !== null && $home['factionId'] === $player->faction_id
                        && $home['readyAt'] !== null && ! isset($helped["{$home['id']}:{$home['level']}"]);
                    unset($home['factionId']);

                    return $home;
                }, $homes);
            })(),
            'home' => [
                'inside' => $player->inside_home_at !== null,
                // On another faction's land: join them by then, or they move it.
                'eviction' => ($own = Homes::of($player)) !== null && $own->foreign_since !== null && $own->region?->faction !== null ? [
                    'faction' => $own->region->faction->name,
                    'at' => CarbonImmutable::instance($own->foreign_since)->addDays(Homes::GRACE_DAYS)->toIso8601String(),
                ] : null,
                // The levels so far and the next one: homes grow without end.
                'levels' => collect(range(0, max(3, (int) (Homes::of($player)?->level ?? 0) + 1)))->map(fn (int $n) => ['level' => $n, ...Homes::level($n)])->values(),
                'door' => Homes::DOOR,
                'styles' => Homes::STYLES,
                // The skin shop and how far up it your home is.
                'skinLevel' => (int) (Homes::of($player)?->skin_level ?? 0),
                'skinShop' => collect(Homes::SKIN_SHOP)->map(fn (array $skin, int $level) => [
                    'level' => $level,
                    'name' => $skin['name'],
                    'colours' => $skin['colours'],
                    'corn' => $skin['corn'],
                    'apples' => $skin['apples'],
                    'defence' => (int) round($skin['defence'] * 100),
                ])->values(),
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

    /**
     * Land on fire: while a plunder is under way, and for a while after a
     * plunder, a raid or Irongate's riders took it; ashes for an hour after.
     *
     * @return array<int, string> region id => burning or ash
     */
    private function scorched(Season $season): array
    {
        $now = now();
        $burnFor = $now->copy()->subMinutes(20);
        // Ashes lie for an hour after, then the land is clear again.
        $ashFor = $now->copy()->subHour();
        $fires = [];
        $mark = function (int $regionId, $at) use (&$fires, $burnFor) {
            $state = $at === null || $at->gt($burnFor) ? 'burning' : 'ash';
            $fires[$regionId] = ($fires[$regionId] ?? null) === 'burning' ? 'burning' : $state;
        };

        $season->crimes()->where('kind', CrimeKind::Plunder)->whereNotNull('region_id')
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere(fn ($query) => $query->where('resolved_at', '>', $ashFor)->where('caught', false)))
            ->get(['region_id', 'resolved_at'])
            ->each(fn ($crime) => $mark($crime->region_id, $crime->resolved_at));
        $season->attacks()->where('winner', 'attack')->where('resolved_at', '>', $ashFor)
            ->get(['region_id', 'resolved_at'])
            ->each(fn (Attack $attack) => $mark($attack->region_id, $attack->resolved_at));
        PlunderBand::query()->where('season_id', $season->id)->where('won', false)->where('resolved_at', '>', $ashFor)
            ->get(['region_id', 'resolved_at'])
            ->each(fn (PlunderBand $band) => $mark($band->region_id, $band->resolved_at));

        return $fires;
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
