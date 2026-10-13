<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\Job;
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
        $check = Money::format(Job::BillCheck->price());
        $dispute = Money::format(Job::Dispute->price());

        $pricing = "You pay as you go from prepaid credit: {$check} to check a bill and {$dispute} for a dispute, which we email to the municipality, chase and escalate for you. No monthly or setup fees.";

        return Inertia::render('settings/account', [
            'account' => [
                'name' => $organisation->name,
                'kind' => $organisation->kind->value,
                'kindLabel' => $organisation->kind->label(),
                'forwardingAddress' => $organisation->forwardingAddress(),
                'pricing' => $pricing,
                'mandateOn' => $organisation->dispute_mandate_at?->format('j M Y'),
                'autoSend' => $organisation->auto_send_disputes,
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

    /**
     * The customer's permission for us to send disputes for them, and
     * whether we send them without asking when a bill shows only likely
     * errors. Withdrawing the permission switches automatic sending off.
     */
    public function updateDisputes(Request $request): RedirectResponse
    {
        $data = $request->validate(['mandate' => ['boolean'], 'auto_send' => ['boolean']]);
        $organisation = $request->user()->organisation;
        $mandate = (bool) ($data['mandate'] ?? false);

        $organisation->forceFill([
            'dispute_mandate_at' => $mandate ? ($organisation->dispute_mandate_at ?? now()) : null,
            'dispute_mandate_user_id' => $mandate ? ($organisation->dispute_mandate_user_id ?? $request->user()->id) : null,
            'auto_send_disputes' => $mandate && (bool) ($data['auto_send'] ?? false),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

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
