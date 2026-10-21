<?php

namespace App\Http\Controllers\Game;

use App\Enums\Study;
use App\Game\Banners;
use App\Game\Research;
use App\Game\Spying;
use App\Http\Controllers\Controller;
use App\Models\Attack;
use App\Models\Building;
use App\Models\Faction;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Any faction's page, for anyone in its valley: its banner, members, land,
 * buildings, studies and raid record this season. What it keeps in its
 * treasury and store stays its own business.
 */
class FactionProfileController extends Controller
{
    public function show(Request $request, Faction $faction): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        abort_unless($faction->valley_id === $player->valley_id, 404);

        $season = $faction->valley->currentSeason();
        $house = $faction->valley->house();
        $raids = $season === null ? collect() : Attack::query()
            ->where('season_id', $season->id)
            ->whereNotNull('resolved_at')
            ->where(fn ($query) => $query->where('faction_id', $faction->id)->orWhere('defender_faction_id', $faction->id))
            ->get(['faction_id', 'defender_faction_id', 'winner']);

        return Inertia::render('game/faction-profile', [
            'faction' => [
                'id' => $faction->id,
                'name' => $faction->name,
                'colour' => $faction->colour,
                'motto' => $faction->motto,
                'banner' => Banners::of($faction),
                'isHouse' => $faction->id === $house?->id,
                'mine' => $faction->id === $player->faction_id,
                'rogue' => (bool) $faction->rogue,
                'founded' => $faction->created_at?->toIso8601String(),
                'entryMode' => $faction->entry_mode->value,
            ],
            'members' => $faction->members()
                ->orderByRaw("CASE faction_role WHEN 'leader' THEN 0 WHEN 'council' THEN 1 WHEN 'member' THEN 2 ELSE 3 END")
                ->orderByDesc('rosettes')
                ->get()
                ->map(fn (Player $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'species' => $member->species->value,
                    'level' => $member->level(),
                    'role' => $member->faction_role?->value,
                ]),
            'land' => $faction->regions()->orderBy('name')->get(['id', 'name', 'ring', 'produces'])->map(fn ($region) => [
                'id' => $region->id,
                'name' => $region->name,
                'ring' => $region->ring,
                'produces' => $region->produces,
            ]),
            'buildings' => Building::query()
                ->with('region:id,name,faction_id')
                ->where('faction_id', $faction->id)
                ->get()
                ->filter(fn (Building $building) => $building->region?->faction_id === $faction->id)
                ->map(fn (Building $building) => [
                    'kind' => $building->kind->label(),
                    'level' => $building->level,
                    'region' => $building->region->name,
                    'goingUp' => $building->ready_at?->isFuture() ?? false,
                ])
                ->values(),
            // A Spyglass shows what the faction keeps to itself, to the spy's faction.
            'spy' => $faction->id === $player->faction_id ? null : [
                'spyglasses' => Spying::spyglasses($player),
                'report' => ($report = Spying::latest($player, $faction)) === null ? null : [
                    ...$report->report,
                    'by' => $report->player?->name,
                    'at' => $report->created_at->toIso8601String(),
                ],
            ],
            'studies' => array_map(fn (Study $study) => ['label' => $study->label(), 'level' => Research::level($faction, $study), 'max' => $study->maxLevel()], Study::cases()),
            'record' => [
                'raidsWon' => $raids->where('faction_id', $faction->id)->where('winner', 'attack')->count(),
                'raidsLost' => $raids->where('faction_id', $faction->id)->where('winner', 'defence')->count(),
                'held' => $raids->where('defender_faction_id', $faction->id)->where('winner', 'defence')->count(),
                'lost' => $raids->where('defender_faction_id', $faction->id)->where('winner', 'attack')->count(),
            ],
        ]);
    }

    /** Look through a Spyglass at a faction. */
    public function spy(Request $request, Faction $faction, Spying $spying): RedirectResponse
    {
        $spying->spy($request->user()->player, $faction);

        return $this->told("You looked through your Spyglass at {$faction->name}. What you saw is on its page, for your faction to read.");
    }
}
