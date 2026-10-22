<?php

namespace App\Http\Controllers\Game;

use App\Game\Finale;
use App\Game\Pets;
use App\Game\Rivalry;
use App\Game\ValleyEvents;
use App\Game\ValleyJobs;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\ValleyEvent;
use App\Models\ValleyJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Valley page: the season's countdown and podium, the Hall of Fame, the
 * weekly rivalry, events on now, the job board and your pet.
 */
class ValleyController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $valley = $player->valley;
        $season = $valley->currentSeason();
        $last = Rivalry::lastWinner($valley->id);

        return Inertia::render('game/valley', [
            'season' => $season === null ? null : [
                'number' => $season->number,
                'endsAt' => $season->ends_at->toIso8601String(),
                'finalDays' => Finale::finalDays($season),
                'finalFrom' => $season->ends_at->subDays(Finale::FINAL_DAYS)->toIso8601String(),
            ],
            'podium' => [
                'factions' => Finale::factions($valley)->map(fn (array $row) => [
                    'id' => $row['faction']->id, 'name' => $row['faction']->name, 'colour' => $row['faction']->colour, 'land' => $row['land'],
                ])->all(),
                'animals' => $season === null ? [] : Finale::animals($season)->map(fn (array $row) => [
                    'id' => $row['player']->id, 'name' => $row['player']->name, 'species' => $row['player']->species->value,
                    'colour' => $row['player']->faction?->colour, 'rosettes' => $row['rosettes'],
                ])->all(),
                'prizes' => ['faction' => Finale::FACTION_PRIZE, 'animals' => Finale::ANIMAL_PRIZES],
            ],
            'hall' => Finale::hall($valley->id),
            'rivalry' => [
                'board' => Rivalry::board($valley),
                'weekOf' => Rivalry::weekStart()->toIso8601String(),
                'last' => $last ? ['id' => $last->faction_id, 'name' => $last->name, 'colour' => $last->colour, 'points' => $last->points, 'weekOf' => $last->week_of?->toDateString()] : null,
                'scoring' => ['raid' => Rivalry::RAID_POINTS, 'land' => Rivalry::LAND_POINTS, 'corn' => Rivalry::CORN_PER_POINT],
            ],
            'events' => ValleyEvents::live($player),
            'jobs' => ValleyJobs::board($player),
            'pet' => Pets::card($player),
        ]);
    }

    public function joinEvent(Request $request, ValleyEvent $event, ValleyEvents $events): RedirectResponse
    {
        return $this->told($events->join($request->user()->player, $event));
    }

    public function bring(Request $request, ValleyJob $job, ValleyJobs $jobs): RedirectResponse
    {
        $jobs->bring($request->user()->player, $job);

        return $this->told("You handed in {$job->amount} {$job->material}: {$job->reward_apples} Apples and {$job->reward_rosettes} Rosettes are yours.");
    }

    public function adopt(Request $request, Pets $pets): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Pets::KINDS))],
            'name' => ['required', 'string', 'max:20'],
        ]);
        $pet = $pets->adopt($request->user()->player, $data['kind'], $data['name']);

        return $this->told(Pets::KINDS[$pet->kind]['emoji']." {$pet->name} is yours. Feed it every day and it grows.");
    }

    public function feed(Request $request, Pets $pets): RedirectResponse
    {
        $pet = $pets->feed($request->user()->player);

        return $this->told("{$pet->name} ate happily".($pet->level >= Pets::MAX_LEVEL ? ', fully grown.' : " and is level {$pet->level}."));
    }
}
