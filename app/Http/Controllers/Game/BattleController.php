<?php

namespace App\Http\Controllers\Game;

use App\Game\Battles;
use App\Game\Clock;
use App\Game\GateNight;
use App\Http\Controllers\Controller;
use App\Models\Attack;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Raids, defending against them, and holding the gate on Gate Night. */
class BattleController extends Controller
{
    public function launch(Request $request, Region $region, Battles $battles): RedirectResponse
    {
        $player = $request->user()->player;
        abort_unless($region->valley_id === $player->valley_id, 404);
        $horseshoes = (int) $request->validate(['horseshoes' => ['required', 'integer', 'between:1,3']])['horseshoes'];

        $attack = $battles->launch($player, $region, $horseshoes);

        return $this->told("Your faction marches on {$region->name}. It lands at ".Clock::local($attack->lands_at)->format('H:i').'. Rally your faction to add horseshoes.');
    }

    public function commit(Request $request, Attack $attack, Battles $battles): RedirectResponse
    {
        $player = $request->user()->player;
        abort_unless($attack->region->valley_id === $player->valley_id, 404);
        $horseshoes = (int) $request->validate(['horseshoes' => ['required', 'integer', 'between:1,3']])['horseshoes'];

        $commitment = $battles->commit($player, $attack, $horseshoes);

        return $this->told($commitment->side === 'attack'
            ? "You joined the attack on {$attack->region->name}."
            : "You joined the defence of {$attack->region->name}.");
    }

    public function gate(Request $request, GateNight $gateNight): RedirectResponse
    {
        $horseshoes = (int) $request->validate(['horseshoes' => ['required', 'integer', 'between:1,3']])['horseshoes'];
        $gateNight->defend($request->user()->player, $horseshoes);

        return $this->told('You took your place at the gate. Ironfield comes at 19:00.');
    }
}
