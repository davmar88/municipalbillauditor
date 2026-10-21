<?php

namespace App\Http\Controllers\Staff;

use App\Game\Market;
use App\Http\Controllers\Controller;
use App\Models\ItemDesign;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A new maker's first three designs are checked by a person before copies
 * can be made: anything offensive, real brands or real people is rejected.
 */
class DesignController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('staff/designs', [
            'designs' => ItemDesign::query()->with('player:id,name')->where('status', 'pending')->oldest('id')->limit(100)->get()
                ->map(fn (ItemDesign $design) => [...$design->toCard(), 'maker' => $design->player->name, 'at' => $design->created_at->toIso8601String()]),
            // What was checked lately, so a check can be seen to have worked.
            'checked' => ItemDesign::query()->with('player:id,name')->whereNotNull('player_id')->whereIn('status', ['approved', 'rejected'])->latest('updated_at')->limit(20)->get()
                ->map(fn (ItemDesign $design) => [...$design->toCard(), 'maker' => $design->player->name, 'at' => $design->updated_at->toIso8601String()]),
        ]);
    }

    public function approve(ItemDesign $design, Market $market): RedirectResponse
    {
        $market->moderate($design, true);

        return $this->told("Approved {$design->name}.");
    }

    public function reject(ItemDesign $design, Market $market): RedirectResponse
    {
        $market->moderate($design, false);

        return $this->told("Rejected {$design->name}.");
    }
}
