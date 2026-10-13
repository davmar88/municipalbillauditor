<?php

namespace App\Http\Controllers;

use App\Enums\DisputeEventKind;
use App\Http\Requests\RecordDisputeEventRequest;
use App\Models\Dispute;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The customer records what happened: a reference number arrived, the
 * municipality replied, they escalated, or the credit showed up.
 */
class DisputeEventController extends Controller
{
    public function store(RecordDisputeEventRequest $request, Dispute $dispute, DisputeService $disputes): RedirectResponse
    {
        $this->authorize('update', $dispute);

        $disputes->record(
            $dispute,
            DisputeEventKind::from($request->validated('kind')),
            CarbonImmutable::parse($request->validated('happened_on')),
            $request->details(),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Updated.']);

        return back();
    }
}
