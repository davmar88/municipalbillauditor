<?php

namespace App\Http\Controllers\Game;

use App\Enums\Species;
use App\Game\Players;
use App\Game\Valleys;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Making your animal, on the first visit to the game. */
class StartController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()->player !== null) {
            return to_route('play');
        }

        return Inertia::render('game/start', [
            // The oldest valley with room; the next one opens when they're all full.
            'valley' => Valleys::preview(),
            'species' => array_map(fn (Species $species) => [
                'value' => $species->value,
                'label' => $species->label(),
                'role' => $species->role(),
                'talent' => $species->talent(),
                'weakSpot' => $species->weakSpot(),
            ], Species::playable()),
            'languages' => Players::LANGUAGES,
            'suggestedName' => str($request->user()->name)->before(' ')->limit(20, '')->toString(),
        ]);
    }

    public function store(Request $request, Players $players): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'species' => ['required', Rule::enum(Species::class)],
            'language' => ['nullable', 'string', 'max:30'],
            'adult' => ['accepted'],
        ], [
            'adult.accepted' => 'The test season is for adults only. Please confirm you are 18 or older.',
        ]);

        $players->start(
            $request->user(),
            Valleys::forNewcomer(),
            $data['name'],
            Species::from($data['species']),
            true,
            $data['language'] ?? 'English',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Welcome to the valley.']);

        return to_route('play');
    }
}
