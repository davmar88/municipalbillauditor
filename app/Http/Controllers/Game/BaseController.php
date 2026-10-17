<?php

namespace App\Http\Controllers\Game;

use App\Enums\BuildingKind;
use App\Game\Bases;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Faction bases: giving to the store, raising buildings and levels. */
class BaseController extends Controller
{
    public function give(Request $request, Bases $bases): RedirectResponse
    {
        $data = $request->validate([
            'what' => ['required', Rule::in(['corn', ...Bases::MATERIALS])],
            'amount' => ['required', 'integer', 'between:1,100000'],
        ]);

        $bases->give($request->user()->player, $data['what'], (int) $data['amount']);
        $what = $data['what'] === 'corn' ? 'Corn' : $data['what'];

        return $this->told("You gave {$data['amount']} {$what} to your faction's store.");
    }

    public function build(Request $request, Region $region, Bases $bases): RedirectResponse
    {
        abort_unless($region->valley_id === $request->user()->player->valley_id, 404);
        $kind = BuildingKind::from($request->validate(['kind' => ['required', Rule::enum(BuildingKind::class)]])['kind']);

        $building = $bases->build($request->user()->player, $region, $kind);

        return $this->told("Your faction is raising a {$kind->label()} in {$region->name}. It's ready in ".$this->duration($kind->minutes($building->level)).'.');
    }

    public function upgrade(Request $request, Building $building, Bases $bases): RedirectResponse
    {
        $building = $bases->upgrade($request->user()->player, $building);

        return $this->told("The {$building->kind->label()} is going up to level {$building->level}. It's ready in ".$this->duration($building->kind->minutes($building->level)).'.');
    }

    private function duration(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).' '.(intdiv($minutes, 60) === 1 ? 'hour' : 'hours') : "{$minutes} minutes";
    }
}
