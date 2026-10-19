<?php

namespace App\Http\Controllers\Game;

use App\Game\Homes;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Your own home: building it where you stand, raising it, hiding in it, dressing it. */
class HomeController extends Controller
{
    public function build(Request $request, Homes $homes): RedirectResponse
    {
        $home = $homes->build($request->user()->player);

        return $this->told('Your Hut is going up. It stands at '.$home->ready_at?->timezone(config('game.timezone'))->format('H:i').'.');
    }

    public function upgrade(Request $request, Homes $homes): RedirectResponse
    {
        $home = $homes->upgrade($request->user()->player);

        return $this->told('Your '.Homes::name($home->level).' is going up. It stands at '.$home->ready_at?->timezone(config('game.timezone'))->format('H:i').'.');
    }

    public function enter(Request $request, Homes $homes): RedirectResponse
    {
        $homes->enter($request->user()->player);

        return $this->told("You're inside. Nobody sees you on the map until you walk out.");
    }

    public function leave(Request $request, Homes $homes): RedirectResponse
    {
        $homes->leave($request->user()->player);

        return $this->told('You stepped outside.');
    }

    public function skin(Request $request, Homes $homes): RedirectResponse
    {
        $data = $request->validate(['item' => ['nullable', 'integer']]);
        $homes->dress($request->user()->player, isset($data['item']) ? (int) $data['item'] : null);

        return $this->told(isset($data['item']) ? 'Your home has its new skin.' : 'Your home is back to plain boards.');
    }
}
