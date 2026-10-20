<?php

namespace App\Http\Controllers\Admin;

use App\Game\Valleys;
use App\Http\Controllers\Controller;
use App\Models\Valley;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's valleys: how full each is, which takes newcomers, and opening the next. */
class ValleyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/valleys', [
            'valleys' => Valleys::overview(),
            'fullAt' => Valleys::fullAt(),
            'nextName' => Valleys::nextName(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $valley = Valleys::open();

        return $this->told("{$valley->name} is open. Newcomers start there once the valleys before it are full or closed.");
    }

    public function update(Request $request, Valley $valley): RedirectResponse
    {
        $closed = (bool) $request->validate(['closed' => ['required', 'boolean']])['closed'];
        $valley->forceFill(['closed_to_newcomers' => $closed])->save();

        return $this->told($closed ? "{$valley->name} takes no more newcomers." : "{$valley->name} takes newcomers again while it has room.");
    }
}
