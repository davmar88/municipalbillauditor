<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\OrganisationKind;
use App\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everyone can hold several accounts (a home, a rental portfolio, a
 * business), add more, edit them, switch between them and remove one. Each
 * account keeps its own prepaid credit, bills and forwarding address.
 */
class AccountsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/accounts', [
            'accounts' => $user->organisations()->withCount('bills')->get()->map(fn (Organisation $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'kind' => $o->kind->value,
                'kindLabel' => $o->kind->label(),
                'balance' => Money::format($o->credit_balance),
                'hasCredit' => Money::of($o->credit_balance)->isPositive(),
                'billCount' => $o->bills_count,
                'current' => $o->id === $user->organisation_id,
            ])->values(),
            'kinds' => array_map(fn (OrganisationKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], OrganisationKind::cases()),
        ]);
    }

    /** Add an account and start working in it. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $organisation = Organisation::create($data);
        $request->user()->organisations()->syncWithoutDetaching([$organisation->id]);
        $request->user()->switchTo($organisation);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$organisation->name} added. You're working in it now."]);

        return to_route('dashboard');
    }

    public function update(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorizeMember($request, $organisation);
        $organisation->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account updated.']);

        return back();
    }

    /**
     * Work in another account. Back to the dashboard: the page they were on
     * belongs to the account they left.
     */
    public function switch(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorizeMember($request, $organisation);
        $request->user()->switchTo($organisation);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Switched to {$organisation->name}."]);

        return to_route('dashboard');
    }

    /**
     * Remove an account. Never the last one, and never one still holding
     * prepaid credit, which would be lost. When nobody else is on it, its
     * bills, files and disputes are deleted with it.
     */
    public function destroy(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorizeMember($request, $organisation);
        $user = $request->user();

        $request->validate(['confirm' => ['required', 'in:DELETE']], ['confirm.in' => 'Type DELETE to confirm.']);

        if ($user->organisations()->count() <= 1) {
            return back()->withErrors(['confirm' => "This is your only account, so it can't be removed."]);
        }

        if (Money::of($organisation->credit_balance)->isPositive()) {
            return back()->withErrors(['confirm' => 'This account still has '.Money::format($organisation->credit_balance).' of prepaid credit. Use it, or ask us to refund it, before removing the account.']);
        }

        $user->organisations()->detach($organisation->id);

        if (! $organisation->users()->exists()) {
            $organisation->purge();
        }

        if ($user->organisation_id === $organisation->id || $user->organisation_id === null) {
            $user->switchTo($user->organisations()->firstOrFail());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$organisation->name} removed."]);

        return to_route('accounts.index');
    }

    /**
     * @return array{kind: OrganisationKind, name: string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(OrganisationKind::class)],
            'name' => ['required', 'string', 'max:255'],
        ], ['name.required' => 'Give the account a name.']);

        return ['kind' => OrganisationKind::from($data['kind']), 'name' => trim($data['name'])];
    }

    /** Someone else's account answers as if it didn't exist. */
    private function authorizeMember(Request $request, Organisation $organisation): void
    {
        abort_unless($request->user()->belongsToOrganisation($organisation), 404);
    }
}
