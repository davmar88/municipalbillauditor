<?php

namespace App\Http\Controllers\Game;

use App\Game\Clock;
use App\Game\Crimes;
use App\Game\Messages;
use App\Game\Warrants;
use App\Http\Controllers\Controller;
use App\Models\Crime;
use App\Models\Player;
use App\Models\Warrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Wanted board: every faction's warrants, bounties, suspects and arrests. */
class WantedController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $season = $player->valley->currentSeason();
        // Any faction's leader and council put out warrants, from their own store.
        $house = $player->faction;
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

        $decides = $house !== null && $player->decides();

        return Inertia::render('game/wanted', [
            'warrants' => $warrants->map(function (Warrant $warrant) use ($player, $here) {
                $where = $warrant->wanted->location();

                return [
                    'id' => $warrant->id,
                    'animal' => Messages::card($warrant->wanted),
                    'bounty' => $warrant->bounty,
                    'payout' => Warrants::payout($warrant->bounty),
                    'issuedBy' => $warrant->faction?->name,
                    // An outlaw lying low in Irongate is only known to be somewhere in the town.
                    'lastSeen' => $where->isCity() ? 'Irongate' : $where->name,
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
            // Who wronged you lately: catch them where they stand, or put a bounty on them.
            'againstMe' => Crimes::against($player)->unique('player_id')->values()->map(function (Crime $crime) use ($here, $warrants) {
                $where = $crime->player->location();

                return [
                    'crimeId' => $crime->id,
                    'animal' => Messages::card($crime->player),
                    'deed' => Warrants::deed($crime),
                    'at' => $crime->resolved_at->toIso8601String(),
                    'until' => $crime->resolved_at->addHours(Crimes::CATCH_HOURS)->toIso8601String(),
                    'lastSeen' => $where->isCity() ? 'Irongate' : $where->name,
                    'here' => $where->id === $here,
                    'inPound' => $crime->player->inPound(),
                    'escapedUntil' => $crime->escaped_until?->isFuture() ? $crime->escaped_until->toIso8601String() : null,
                    'warranted' => $warrants->contains('player_id', $crime->player_id),
                ];
            }),
            'apples' => (int) $player->apples,
            'catch' => ['horseshoes' => Crimes::CATCH_HORSESHOES, 'chance' => (int) round(Crimes::CATCH_CHANCE * 100), 'hours' => Crimes::CATCH_HOURS],
            'rules' => [
                'minBounty' => Warrants::MIN_BOUNTY,
                'maxBounty' => Warrants::MAX_BOUNTY,
                'horseshoes' => Warrants::ARREST_HORSESHOES,
                'chance' => (int) round(Warrants::ARREST_CHANCE * 100),
                'dogChance' => (int) round((Warrants::ARREST_CHANCE + Warrants::DOG_BONUS) * 100),
                'escapeMinutes' => Warrants::ESCAPE_MINUTES,
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

    public function catch(Request $request, Crime $crime, Crimes $crimes): RedirectResponse
    {
        $name = $crime->player->name;

        return $crimes->catchCulprit($request->user()->player, $crime)
            ? $this->told("You caught {$name}! They're in the pound now.")
            : $this->told("{$name} slipped away. You can try again in ".Warrants::ESCAPE_MINUTES.' minutes.', 'error');
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
