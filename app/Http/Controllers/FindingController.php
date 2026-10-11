<?php

namespace App\Http\Controllers;

use App\Audit\Enums\CheckType;
use App\Audit\Enums\PropertyType;
use App\Enums\FindingState;
use App\Models\Finding;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The customer's side of a finding: answering its question, or saying it
 * isn't an error. Their answers decide what goes into a dispute.
 */
class FindingController extends Controller
{
    public function answer(Request $request, Finding $finding, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $finding);
        abort_if($finding->bill->isLocked(), 403, 'This bill is already in a dispute.');

        $options = array_column($finding->answer_options ?? [], 0);
        $answer = $request->validate(['answer' => ['required', Rule::in($options)]])['answer'];

        // "What kind of property is this?" updates the property and re-checks.
        if ($finding->check_type === CheckType::WrongTariff) {
            $finding->bill->property->update(['property_type' => PropertyType::from($answer)]);
            $audit->run($finding->bill->refresh());

            return back();
        }

        $state = match ($answer) {
            'no', 'expected' => FindingState::Dismissed,
            'yes', 'keep' => FindingState::Confirmed,
            default => FindingState::Open,
        };

        $finding->update(['answer' => $answer, 'answered_at' => now(), 'state' => $state]);

        return back();
    }

    public function dismiss(Finding $finding): RedirectResponse
    {
        $this->authorize('update', $finding);
        abort_if($finding->bill->isLocked(), 403, 'This bill is already in a dispute.');

        $finding->update(['state' => FindingState::Dismissed, 'answer' => $finding->answer ?? 'not_an_error', 'answered_at' => now()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Thanks. We won't count that one."]);

        return back();
    }

    public function restore(Finding $finding): RedirectResponse
    {
        $this->authorize('update', $finding);
        abort_if($finding->bill->isLocked(), 403, 'This bill is already in a dispute.');

        $finding->update(['state' => FindingState::Open, 'answer' => null, 'answered_at' => null]);

        return back();
    }
}
