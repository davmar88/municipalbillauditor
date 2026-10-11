<?php

namespace App\Http\Controllers;

use App\Enums\OrganisationKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Each kind of customer starts somewhere different: households at their
 * bills, agents at their portfolio, businesses at their claims.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return match ($request->user()->organisation->kind) {
            OrganisationKind::Household => to_route('bills.index'),
            OrganisationKind::Agent => to_route('portfolio'),
            OrganisationKind::Business => to_route('disputes.index'),
        };
    }
}
