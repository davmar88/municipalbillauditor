<?php

namespace App\Http\Controllers\Game;

use App\Game\Alliances;
use App\Http\Controllers\Controller;
use App\Models\Alliance;
use App\Models\Faction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Offering, answering and breaking alliances between factions. */
class AllianceController extends Controller
{
    public function propose(Request $request, Faction $faction, Alliances $alliances): RedirectResponse
    {
        $alliances->propose($request->user()->player, $faction);

        return $this->told("You offered {$faction->name} an alliance. Their leader or council will answer.");
    }

    public function answer(Request $request, Alliance $alliance, Alliances $alliances): RedirectResponse
    {
        $accept = (bool) $request->validate(['accept' => ['required', 'boolean']])['accept'];
        $alliances->answer($request->user()->player, $alliance, $accept);

        return $this->told($accept ? 'You are allies now.' : 'You declined the alliance.');
    }

    public function break(Request $request, Alliances $alliances): RedirectResponse
    {
        $alliances->break($request->user()->player);

        return $this->told('You broke the alliance. The whole valley knows.');
    }
}
