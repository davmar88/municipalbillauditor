<?php

namespace App\Http\Controllers\Game;

use App\Game\Clock;
use App\Game\Plunderers;
use App\Http\Controllers\Controller;
use App\Models\PlunderBand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Riding out against a band of Irongate's plunderers. */
class PlunderController extends Controller
{
    public function fight(Request $request, PlunderBand $band, Plunderers $plunderers): RedirectResponse
    {
        $horseshoes = (int) $request->validate(['horseshoes' => ['required', 'integer', 'between:1,3']])['horseshoes'];
        $plunderers->fight($request->user()->player, $band, $horseshoes);

        return $this->told("You're riding out against the plunderers. They strike {$band->region->name} at ".Clock::local($band->lands_at)->format('H:i').'.');
    }
}
