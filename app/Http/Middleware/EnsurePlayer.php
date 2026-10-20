<?php

namespace App\Http\Middleware;

use App\Game\Live;
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
        $response = $next($request);

        // Anything an animal did that went through shows on everyone's map straight away.
        if (! $request->isMethodSafe() && $response->isRedirection() && ! $request->session()->has('errors')) {
            Live::touch($player->valley_id);
            Live::flush();
        }

        return $response;
    }
}
