<?php

namespace App\Http\Controllers\Game;

use App\Game\Census;
use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Who's online in your valley right now. */
class OnlineController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;

        return Inertia::render('game/online', [
            'valley' => $player->valley->name,
            'animals' => Census::onlineIn($player->valley),
            'total' => $player->valley->players()->count(),
            'minutes' => Census::ONLINE_MINUTES,
        ]);
    }
}
