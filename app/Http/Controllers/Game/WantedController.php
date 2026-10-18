<?php

namespace App\Http\Controllers\Game;

use App\Game\Clock;
use App\Game\Messages;
use App\Game\Warrants;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Warrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Wanted board: the House's warrants, bounties, suspects and arrests. */
class WantedController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $season = $player->valley->currentSeason();
        $house = $player->valley->house();
        $here = $player->location()->id;

        $warrants = $season === null ? collect() : Warrant::query()
            ->with(['wanted.faction:id,name,colour', 'faction:id,name,colour'])
            ->where('season_id', $season->id)
            ->where('status', 'open')
            ->orderByDesc('bounty')
            ->get();

        $closed = $season === null ? collect() : Warrant::query()
            ->with(['wanted:id,name', 'hunter:id,name'])
            ->where('season_id', $season->id)
            ->where('status', 'served')
            ->latest('served_at')
            ->limit(8)
            ->get();

        $decides = $house !== null && $player->faction_id === $house->id && $player->decides();

        return Inertia::render('game/wanted', [
            'warrants' => $warrants->map(function (Warrant $warrant) use ($player, $here) {
                $where = $warrant->wanted->location();

                return [
                    'id' => $warrant->id,
                    'animal' => Messages::card($warrant->wanted),
                    'bounty' => $warrant->bounty,
                    'payout' => Warrants::payout($warrant->bounty),
                    'issuedBy' => $warrant->faction?->name,
                    'lastSeen' => $where->name,
                    'inPound' => $warrant->wanted->inPound(),
                    'escapedUntil' => $warrant->escaped_until?->isFuture() ? $warrant->escaped_until->toIso8601String() : null,
                    'mine' => $warrant->player_id === $player->id,
                    'here' => $where->id === $here,
                ];
            }),
            'caught' => $closed->map(fn (Warrant $warrant) => [
                'id' => $warrant->id,
                'text' => "{$warrant->hunter?->name} caught {$warrant->wanted->name} and collected ".Warrants::payout($warrant->bounty).' Apples.',
                'at' => $warrant->served_at?->toIso8601String(),
            ]),
            'house' => $decides ? [
                'name' => $house->name,
                'apples' => $house->apples,
                'suspects' => Warrants::suspects($season)
                    ->reject(fn (array $suspect) => $suspect['player']->id === $player->id)
                    ->map(fn (array $suspect) => [
                        'animal' => Messages::card($suspect['player']),
                        'deed' => Warrants::deed($suspect['crime']),
                        'at' => $suspect['crime']->created_at->toIso8601String(),
                        'warranted' => $warrants->contains('player_id', $suspect['player']->id),
                    ])
                    ->values(),
            ] : null,
            'houseName' => $house?->name,
            'rules' => [
                'minBounty' => Warrants::MIN_BOUNTY,
                'maxBounty' => Warrants::MAX_BOUNTY,
                'horseshoes' => Warrants::ARREST_HORSESHOES,
                'chance' => (int) round(Warrants::ARREST_CHANCE * 100),
                'dogChance' => (int) round((Warrants::ARREST_CHANCE + Warrants::DOG_BONUS) * 100),
                'escapeMinutes' => Warrants::ESCAPE_MINUTES,
                'cut' => Warrants::cut(),
            ],
        ]);
    }

    public function issue(Request $request, Warrants $warrants): RedirectResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'integer'],
            'bounty' => ['required', 'integer', 'min:1'],
        ]);

        $player = $request->user()->player;
        $suspect = Player::query()->where('valley_id', $player->valley_id)->findOrFail($data['player_id']);
        $warrants->issue($player, $suspect, (int) $data['bounty']);

        return $this->told("The warrant for {$suspect->name} is out, with a bounty of {$data['bounty']} Apples.");
    }

    public function raise(Request $request, Warrant $warrant, Warrants $warrants): RedirectResponse
    {
        $amount = (int) $request->validate(['amount' => ['required', 'integer', 'min:1']])['amount'];
        $warrants->raise($request->user()->player, $warrant, $amount);

        return $this->told("You added {$amount} Apples to the bounty on {$warrant->wanted->name}.");
    }

    public function arrest(Request $request, Warrant $warrant, Warrants $warrants): RedirectResponse
    {
        $caught = $warrants->arrest($request->user()->player, $warrant);
        $warrant->refresh();

        return $caught
            ? $this->told("You caught {$warrant->wanted->name}! You collected ".Warrants::payout($warrant->bounty).' Apples.')
            : $this->told("{$warrant->wanted->name} slipped away. You can pick up the trail at ".Clock::local($warrant->escaped_until)->format('H:i').'.', 'error');
    }
}
