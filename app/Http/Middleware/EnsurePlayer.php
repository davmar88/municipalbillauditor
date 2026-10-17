<?php

namespace App\Http\Middleware;

use App\Game\Players;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Game pages need the user's animal. Without one, the player is sent to
 * make it first. Each day's first visit earns its Rosettes here.
 */
class EnsurePlayer
{
    public function __construct(private readonly Players $players) {}

    public function handle(Request $request, Closure $next): Response
    {
        $player = $request->user()?->player;

        if ($player === null) {
            return to_route('play.start');
        }

        $this->players->visit($player);

        return $next($request);
    }
}
