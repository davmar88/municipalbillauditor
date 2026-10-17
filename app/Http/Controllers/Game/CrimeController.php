<?php

namespace App\Http\Controllers\Game;

use App\Enums\CrimeKind;
use App\Game\Crimes;
use App\Http\Controllers\Controller;
use App\Models\Faction;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lone wolves' crimes, and rebuilding a burned home. */
class CrimeController extends Controller
{
    public function store(Request $request, Crimes $crimes): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(CrimeKind::class)],
            'faction_id' => ['nullable', 'integer'],
            'player_id' => ['nullable', 'integer'],
        ]);

        $player = $request->user()->player;
        $kind = CrimeKind::from($data['kind']);

        $crimes->commit(
            $player,
            $kind,
            isset($data['faction_id']) ? Faction::query()->where('valley_id', $player->valley_id)->find($data['faction_id']) : null,
            isset($data['player_id']) ? Player::query()->where('valley_id', $player->valley_id)->find($data['player_id']) : null,
        );

        return $this->told('It is done. Find out in half an hour whether you got away with it.');
    }

    public function rebuild(Request $request, Crimes $crimes): RedirectResponse
    {
        $crimes->rebuild($request->user()->player);

        return $this->told('Your home is rebuilt and your shop is open again.');
    }
}
