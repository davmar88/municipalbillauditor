<?php

namespace App\Http\Controllers\Game;

use App\Game\Exchange;
use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The Market as an exchange: what sells most and least, and how much of each is about. */
class ExchangeController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $board = Exchange::board($player->valley);
        $traded = $board->where('sold', '>', 0);

        return Inertia::render('game/exchange', [
            'board' => $board->sortByDesc('soldWeek')->sortByDesc('sold')->values(),
            'kinds' => Exchange::kinds($board),
            'most' => $traded->sortByDesc('sold')->take(5)->values(),
            // Designs with copies about that sell least, unsold ones first.
            'least' => $board->sortBy([['sold', 'asc'], ['circulating', 'desc']])->take(5)->values(),
            'totals' => [
                'designs' => $board->count(),
                'circulating' => (int) $board->sum('circulating'),
                'sold' => (int) $board->sum('sold'),
                'soldWeek' => (int) $board->sum('soldWeek'),
            ],
            'weekDays' => Exchange::WEEK_DAYS,
        ]);
    }
}
