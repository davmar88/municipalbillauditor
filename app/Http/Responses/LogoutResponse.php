<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * After logging out, a full page load of the home page. The home page is
 * server-rendered Blade, not an Inertia page, so a plain redirect from the
 * app would show it in Inertia's error overlay on top of the app.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : Inertia::location(url(Fortify::redirects('logout', '/')));
    }
}
