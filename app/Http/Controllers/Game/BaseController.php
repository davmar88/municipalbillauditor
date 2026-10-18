<?php

namespace App\Http\Controllers\Game;

use App\Enums\BuildingKind;
use App\Enums\Study;
use App\Game\Bases;
use App\Game\Clock;
use App\Game\Research;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Faction bases: giving to the store, raising buildings and levels, and studies. */
class BaseController extends Controller
{
    public function give(Request $request, Bases $bases): RedirectResponse
    {
        $data = $request->validate([
            'what' => ['required', Rule::in(['corn', 'apples', ...Bases::MATERIALS])],
            'amount' => ['required', 'integer', 'between:1,100000'],
        ]);

        $bases->give($request->user()->player, $data['what'], (int) $data['amount']);
        $what = match ($data['what']) {
            'corn' => 'Corn',
            'apples' => 'Apples',
            default => $data['what'],
        };

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

    public function help(Request $request, Building $building, Bases $bases): RedirectResponse
    {
        $building = $bases->help($request->user()->player, $building);

        return $this->told("You lent a hand: {$building->builders()} are building the {$building->kind->label()}, and it's ready at ".Clock::local($building->ready_at)->format('H:i').'.');
    }

    public function study(Request $request, Research $research): RedirectResponse
    {
        $study = Study::from($request->validate(['study' => ['required', Rule::enum(Study::class)]])['study']);

        $faction = $research->start($request->user()->player, $study);
        $level = Research::level($faction, $study) + 1;

        return $this->told("Your faction is studying {$study->label()} {$level}. It's done at ".Clock::local($faction->studying_until)->format('H:i').'.');
    }

    private function duration(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).' '.(intdiv($minutes, 60) === 1 ? 'hour' : 'hours') : "{$minutes} minutes";
    }
}
