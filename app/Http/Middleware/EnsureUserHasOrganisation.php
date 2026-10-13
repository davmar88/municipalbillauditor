<?php

namespace App\Http\Middleware;

use App\Enums\OrganisationKind;
use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every customer works inside an organisation. Accounts created before that
 * (or by staff tools) get a household of their own on first visit.
 */
class EnsureUserHasOrganisation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->organisation_id === null) {
            $organisation = Organisation::create(['kind' => OrganisationKind::Household, 'name' => $user->name]);
            $user->organisation()->associate($organisation)->save();
        }

        return $next($request);
    }
}
