<?php

namespace App\Http\Controllers\Game;

use App\Game\Survival;
use App\Http\Controllers\Controller;
use App\Models\LootDrop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Eating, drinking, water bags, and picking up what the dead left behind. */
class SurvivalController extends Controller
{
    public function eat(Request $request, Survival $survival): RedirectResponse
    {
        $eaten = $survival->eat($request->user()->player);

        return $this->told("🌽 You ate {$eaten} Corn.");
    }

    public function drink(Request $request, Survival $survival): RedirectResponse
    {
        $drank = $survival->drink($request->user()->player);

        return $this->told(match ($drank['from']) {
            'bag' => "💧 You drank {$drank['used']} water from your bag.",
            'store' => "💧 You drank {$drank['used']} water, some of it from your faction's store.",
            default => "💧 You drank your fill at {$drank['from']}.",
        });
    }

    public function barrel(Request $request, Survival $survival): RedirectResponse
    {
        $name = $survival->buyBarrel($request->user()->player);

        return $this->told("You carry your water in a {$name} now.");
    }

    public function pickUp(Request $request, LootDrop $drop, Survival $survival): RedirectResponse
    {
        $took = $survival->pickUp($request->user()->player, $drop);
        $parts = array_filter([
            $took['corn'] > 0 ? "{$took['corn']} Corn" : null,
            ...array_map(fn ($name, $amount) => "{$amount} {$name}", array_keys($took['materials']), $took['materials']),
        ]);

        return $this->told('💰 You picked up '.implode(', ', $parts).'.');
    }
}
