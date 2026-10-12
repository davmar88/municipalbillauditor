<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\Job;
use App\Services\Billing\Wallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organisation's settings: its name, its forwarding address, what it
 * pays and the card it pays with.
 */
class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        $organisation = $request->user()->organisation;
        $check = Money::format(Job::BillCheck->price());
        $dispute = Money::format(Job::Dispute->price());

        $pricing = $organisation->paysMonthly()
            ? Money::format((string) $organisation->monthlyFee()).' a month, with '.Wallet::includedChecks()
                ." bill checks and unlimited disputes included. Extra bill checks are {$check} each from your prepaid credit."
            : "You pay from prepaid credit: {$check} to check a bill and {$dispute} for a dispute, which we email to the municipality, chase and escalate for you.";

        return Inertia::render('settings/account', [
            'account' => [
                'name' => $organisation->name,
                'kind' => $organisation->kind->value,
                'kindLabel' => $organisation->kind->label(),
                'forwardingAddress' => $organisation->forwardingAddress(),
                'pricing' => $pricing,
                'billCount' => $organisation->bills()->count(),
                'card' => $organisation->card_last4 !== null ? [
                    'last4' => $organisation->card_last4,
                    'brand' => Str::title((string) $organisation->card_brand),
                    'expiry' => $organisation->card_expiry,
                ] : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->user()->organisation->update($request->validate(['name' => ['required', 'string', 'max:255']]));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account updated.']);

        return back();
    }

    /**
     * Forget the saved card. Monthly fees then come with a link to pay.
     */
    public function destroyCard(Request $request): RedirectResponse
    {
        $request->user()->organisation->forceFill([
            'card_authorization' => null,
            'card_last4' => null,
            'card_brand' => null,
            'card_expiry' => null,
            'card_email' => null,
            'card_saved_at' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Card removed. We'll email you a link to pay each month instead."]);

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
