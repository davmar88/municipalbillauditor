<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\OrganisationKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's settings: its name, its forwarding address and what it
 * pays.
 */
class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $organisation = $request->user()->organisation;

        $pricing = match ($organisation->kind) {
            OrganisationKind::Household => 'Bill checks are free. A managed dispute is '.Money::format(config('auditor.prices.household_dispute')).'.',
            OrganisationKind::Agent => Money::format($organisation->unitPrice()).' per unit per month, disputes included.',
            OrganisationKind::Business => 'A success fee of '.rtrim(rtrim((string) Money::of($organisation->successFeeRate())->multipliedBy(100), '0'), '.')
                .'% on money that reaches your municipal account, and nothing if it doesn\'t.',
        };

        return Inertia::render('settings/account', [
            'account' => [
                'name' => $organisation->name,
                'kind' => $organisation->kind->value,
                'kindLabel' => $organisation->kind->label(),
                'forwardingAddress' => $organisation->forwardingAddress(),
                'pricing' => $pricing,
                'billCount' => $organisation->bills()->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->user()->organisation->update($request->validate(['name' => ['required', 'string', 'max:255']]));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account updated.']);

        return back();
    }

    public function destroyBills(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:DELETE']], ['confirm.in' => 'Type DELETE to confirm.']);

        // One by one, so each bill's file is deleted with it.
        $request->user()->organisation->bills()->get()->each->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'All your bills and their files are deleted.']);

        return back();
    }
}
