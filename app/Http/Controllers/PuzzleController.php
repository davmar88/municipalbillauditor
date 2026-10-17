<?php

namespace App\Http\Controllers;

use App\Game\Clock;
use App\Game\Puzzle;
use App\Game\Rosettes;
use App\Models\RosetteEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spot the Edit, the free daily puzzle. Anyone can play without signing
 * in; players with an animal earn Rosettes for solving it.
 */
class PuzzleController extends Controller
{
    public function show(Request $request, Puzzle $puzzle): Response
    {
        $player = $request->user()?->player;
        $today = Clock::today();

        return Inertia::render('puzzle', [
            'puzzle' => $puzzle->forDate($today),
            'player' => $player !== null,
            'solvedToday' => $player !== null && RosetteEntry::query()->where('reference', "puzzle:{$player->id}:{$today}")->exists(),
        ]);
    }

    public function solve(Request $request, Puzzle $puzzle): RedirectResponse
    {
        $player = $request->user()->player;
        abort_if($player === null, 403);

        $data = $request->validate([
            'answer' => ['required', 'integer', 'between:0,4'],
            'seconds' => ['required', 'integer', 'min:0', 'max:3600'],
        ]);

        $today = Clock::today();
        $todays = $puzzle->forDate($today);

        if ((int) $data['answer'] !== $todays['answer']) {
            return $this->told('Not that one. Look again tomorrow.', 'info');
        }

        $rosettes = match (true) {
            $data['seconds'] <= 10 => 15,
            $data['seconds'] <= 30 => 10,
            default => 5,
        };

        Rosettes::award($player, $rosettes, 'Solved Spot the Edit', "puzzle:{$player->id}:{$today}", $player->valley->currentSeason());

        return $this->told("Spotted it! {$rosettes} Rosettes for {$player->name}.");
    }
}
