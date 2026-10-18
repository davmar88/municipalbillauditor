<?php

namespace App\Http\Controllers\Game;

use App\Game\Clock;
use App\Game\Orchard;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Orchard membership: joining or renewing with Apples, and the free daily refill. */
class OrchardController extends Controller
{
    public function join(Request $request): RedirectResponse
    {
        $membership = Orchard::join($request->user()->player);
        $until = Clock::local($membership['until'])->format('j M');

        return $this->told($membership['renewed']
            ? "Your Orchard membership now runs until {$until}."
            : "Welcome to the Orchard! Your membership runs until {$until}.");
    }

    public function refill(Request $request): RedirectResponse
    {
        Orchard::refill($request->user()->player);

        return $this->told('Your horseshoes are full again. Your next free refill is ready tomorrow.');
    }
}
