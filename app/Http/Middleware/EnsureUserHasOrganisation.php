<?php

namespace App\Http\Middleware;

use App\Enums\OrganisationKind;
use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every customer works inside an organisation. Without one (an account they
 * worked in was removed, or staff tools made the user), they move to another
 * of their accounts, or get a household of their own.
 */
class EnsureUserHasOrganisation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->organisation_id === null) {
            $user->switchTo($user->organisations()->first()
                ?? Organisation::create(['kind' => OrganisationKind::Household, 'name' => $user->name]));
        }

        return $next($request);
    }
}
