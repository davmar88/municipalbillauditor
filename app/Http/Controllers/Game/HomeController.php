<?php

namespace App\Http\Controllers\Game;

use App\Game\Homes;
use App\Http\Controllers\Controller;
use App\Models\Home;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Your own home: building it where you stand, raising it, hiding in it, dressing it. */
class HomeController extends Controller
{
    public function build(Request $request, Homes $homes): RedirectResponse
    {
        $data = $request->validate(['level' => ['nullable', 'integer', 'in:0,1']]);
        $home = $homes->build($request->user()->player, (int) ($data['level'] ?? 1));

        return $this->told('Your '.Homes::name($home->level).' is going up. It stands at '.$home->ready_at?->timezone(config('game.timezone'))->format('H:i').'.');
    }

    public function camp(Request $request, Homes $homes): RedirectResponse
    {
        $data = $request->validate(['x' => ['required', 'numeric'], 'y' => ['required', 'numeric'], 'level' => ['nullable', 'integer', 'in:0,1']]);
        $level = (int) ($data['level'] ?? 1);
        $name = Homes::name($level);

        return $homes->campAt($request->user()->player, (float) $data['x'], (float) $data['y'], $level)
            ? $this->told("You set up camp. Your {$name} is going up.")
            : $this->told("You set off to set up camp. Your {$name} goes up when you get there.");
    }

    public function upgrade(Request $request, Homes $homes): RedirectResponse
    {
        $home = $homes->upgrade($request->user()->player);

        return $this->told('Your '.Homes::name($home->level).' is going up. It stands at '.$home->ready_at?->timezone(config('game.timezone'))->format('H:i').'.');
    }

    public function help(Request $request, Home $home, Homes $homes): RedirectResponse
    {
        $homes->help($request->user()->player, $home);

        return $this->told("You lent a hand. {$home->owner->name}'s home goes up a third faster.");
    }

    public function douse(Request $request, Home $home, Homes $homes): RedirectResponse
    {
        $homes->douse($request->user()->player, $home);

        return $this->told('You put the fire out. The home still stands, and whoever set it was seen.');
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

    public function demolish(Request $request, Homes $homes): RedirectResponse
    {
        $back = $homes->demolish($request->user()->player);
        $list = collect($back)->map(fn (int $amount, string $name) => "{$amount} {$name}")->implode(', ');

        return $this->told('You broke your home down'.($list !== '' ? " and got back {$list}." : '.'));
    }

    public function style(Request $request, Homes $homes): RedirectResponse
    {
        $data = $request->validate(['style' => ['nullable', 'string', 'max:20']]);
        $home = $homes->style($request->user()->player, $data['style'] ?? null);

        return $this->told('Your home is now a '.($home->style ? Homes::STYLES[$home->style] : 'village house').' on the 3D map.');
    }

    public function buySkin(Request $request, Homes $homes): RedirectResponse
    {
        $home = $homes->buySkin($request->user()->player);
        $skin = Homes::SKIN_SHOP[$home->skin_level];

        return $this->told("Your home wears {$skin['name']} now: ".(int) round($skin['defence'] * 100).' points harder to burn down or to arrest anyone inside.');
    }

    public function skin(Request $request, Homes $homes): RedirectResponse
    {
        $data = $request->validate(['item' => ['nullable', 'integer']]);
        $homes->dress($request->user()->player, isset($data['item']) ? (int) $data['item'] : null);

        return $this->told(isset($data['item']) ? 'Your home has its new skin.' : 'Your home is back to plain boards.');
    }
}
