<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\Job;
use App\Enums\OrganisationKind;
use App\Http\Presenters\BillPresenter;
use App\Http\Presenters\DisputePresenter;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\Finding;
use App\Services\Billing\Wallet;
use App\Services\Disputes\DisputeApproval;
use App\Services\Disputes\RecipientFinder;
use App\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DisputeController extends Controller
{
    public function index(Request $request): Response
    {
        $organisation = $request->user()->organisation;

        $disputes = $organisation->disputes()
            ->whereNotNull('submitted_on')
            ->with(['property.municipality', 'bill', 'findings'])
            ->latest('submitted_on')
            ->latest('id')
            ->get();

        return Inertia::render('disputes/index', [
            'disputes' => $disputes->map(fn (Dispute $d) => DisputePresenter::summary($d))->values(),
            'isBusiness' => $organisation->kind === OrganisationKind::Business,
            'drafts' => $organisation->bills()
                ->where('status', 'checked')
                ->whereDoesntHave('disputes', fn ($q) => $q->whereNotNull('submitted_on'))
                ->whereHas('findings', fn ($q) => $q->where('state', '!=', 'dismissed'))
                ->with(['property', 'findings'])
                ->latest('bill_date')
                ->limit(10)
                ->get()
                ->map(fn (Bill $bill) => BillPresenter::summary($bill))
                ->values(),
        ]);
    }

    public function create(Request $request, Bill $bill, DisputeService $disputes, Wallet $wallet, RecipientFinder $recipients): Response|RedirectResponse
    {
        $this->authorize('update', $bill);

        if ($sent = $bill->disputes()->whereNotNull('submitted_on')->latest('id')->first()) {
            return to_route('disputes.show', $sent);
        }

        try {
            $dispute = $disputes->draft($bill, $request->user());
        } catch (ValidationException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'There is nothing on this bill to dispute yet.']);

            return to_route('bills.show', $bill);
        }

        $organisation = $bill->organisation;
        $municipality = $bill->property->municipality;

        return Inertia::render('disputes/create', [
            'bill' => BillPresenter::summary($bill->load('property', 'findings')),
            'dispute' => [
                'id' => $dispute->id,
                'letterSubject' => $dispute->letter_subject,
                'letterBody' => $dispute->letter_body,
                'disputedTotal' => Money::format($dispute->disputed_total),
                'undisputedAmount' => $dispute->undisputed_amount !== null ? Money::format($dispute->undisputed_amount) : null,
                'deadline' => $dispute->dispute_deadline?->format('j M Y'),
                'findings' => $dispute->findings->map(fn (Finding $f) => BillPresenter::finding($f))->values(),
            ],
            'municipality' => [
                'name' => $municipality?->name ?? 'your municipality',
                'rulesVerified' => (bool) $municipality?->rules_verified,
            ],
            // Where it will go, worked out for the customer.
            'recipient' => $recipients->for($dispute),
            'hasMandate' => $organisation->hasMandate(),
            'kind' => $organisation->kind->value,
            'quote' => $wallet->quote($organisation, Job::Dispute)->toArray(),
            'pendingQuestions' => $bill->findings->filter(fn (Finding $f) => $f->awaitingAnswer())->count(),
            'attachments' => array_values(array_filter([
                $bill->file_path ? ['name' => $bill->file_name ?? 'Your bill', 'url' => route('bills.file', $bill)] : null,
                $bill->meter_photo_path ? ['name' => 'Meter photo', 'url' => route('bills.meter-photo.show', $bill)] : null,
            ])),
        ]);
    }

    public function store(Request $request, Bill $bill, DisputeService $disputes, DisputeApproval $approval): RedirectResponse
    {
        $this->authorize('update', $bill);

        $data = $request->validate([
            'letter_body' => ['required', 'string', 'max:20000'],
            'mandate' => ['boolean'],
        ]);

        $dispute = $disputes->draft($bill, $request->user());
        $dispute = $approval->approve($dispute, $request->user(), $data['letter_body'], $request->boolean('mandate'));

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $dispute->lodged_on !== null => "Sent to {$dispute->sent_to}. We've copied you in.",
            $dispute->held_reason === 'no_address' => "Approved. We're finding the municipality's email address and will send it as soon as we have it.",
            default => "Approved. We're sending it now and will copy you in.",
        }]);

        return to_route('disputes.show', $dispute);
    }

    /**
     * A file the municipality attached to a reply, streamed to the
     * dispute's owner only.
     */
    public function messageFile(Dispute $dispute, DisputeMessage $message, int $index): StreamedResponse
    {
        $this->authorize('view', $dispute);
        abort_unless($message->dispute_id === $dispute->id && $message->kind !== 'customer', 404);

        return self::streamAttachment($message, $index);
    }

    public static function streamAttachment(DisputeMessage $message, int $index): StreamedResponse
    {
        $file = $message->attachments[$index] ?? abort(404);

        return Storage::disk(config('auditor.bill_disk'))->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function show(Request $request, Dispute $dispute, DisputeService $disputes): Response
    {
        $this->authorize('view', $dispute);

        $detail = DisputePresenter::detail($dispute);
        $letter = in_array($detail['stage'], ['reply_overdue', 'with_councillor', 'councillor_overdue'], true)
            ? $disputes->councillorLetter($dispute, $request->user())
            : null;

        return Inertia::render('disputes/show', [
            'dispute' => $detail,
            'councillorLetter' => $letter ? ['subject' => $letter->subject, 'body' => $letter->body] : null,
            'isBusiness' => $dispute->organisation->kind === OrganisationKind::Business,
        ]);
    }
}
