<?php

namespace App\Http\Controllers\Game;

use App\Game\Census;
use App\Game\Messages;
use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
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

    /** The slide-outs behind the corner buttons: who's online to chat with, and your latest chats. */
    public function dock(Request $request, Messages $messages): JsonResponse
    {
        /** @var Player $player */
        $player = $request->user()->player;

        return response()->json([
            'online' => Census::onlineIn($player->valley)
                ->reject(fn (array $animal) => $animal['id'] === $player->id)
                ->take(200)
                ->map(fn (array $animal) => [
                    'id' => $animal['id'],
                    'name' => $animal['name'],
                    'species' => $animal['species'],
                    'faction' => $animal['faction']['name'] ?? null,
                    'colour' => $animal['faction']['colour'] ?? null,
                    'orchard' => false,
                    'level' => $animal['level'],
                    'where' => $animal['where'],
                ])
                ->values(),
            'conversations' => $messages->inbox($player, 20),
        ]);
    }
}
