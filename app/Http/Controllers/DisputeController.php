<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\DisputeMode;
use App\Enums\OrganisationKind;
use App\Http\Presenters\BillPresenter;
use App\Http\Presenters\DisputePresenter;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Finding;
use App\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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

    public function create(Request $request, Bill $bill, DisputeService $disputes): Response|RedirectResponse
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
                'mode' => $dispute->mode->value,
                'letterSubject' => $dispute->letter_subject,
                'letterBody' => $dispute->letter_body,
                'disputedTotal' => Money::format($dispute->disputed_total),
                'undisputedAmount' => $dispute->undisputed_amount !== null ? Money::format($dispute->undisputed_amount) : null,
                'deadline' => $dispute->dispute_deadline?->format('j M Y'),
                'findings' => $dispute->findings->map(fn (Finding $f) => BillPresenter::finding($f))->values(),
            ],
            'municipality' => [
                'name' => $municipality?->name ?? 'your municipality',
                'addressee' => $municipality?->billing_addressee,
                'email' => $municipality?->billing_email,
                'rulesVerified' => (bool) $municipality?->rules_verified,
            ],
            'kind' => $organisation->kind->value,
            'modes' => $this->modes($organisation->kind),
            'pendingQuestions' => $bill->findings->filter(fn (Finding $f) => $f->awaitingAnswer())->count(),
            'attachments' => array_values(array_filter([
                $bill->file_path ? ['name' => $bill->file_name ?? 'Your bill', 'url' => route('bills.file', $bill)] : null,
                $bill->meter_photo_path ? ['name' => 'Meter photo', 'url' => route('bills.meter-photo.show', $bill)] : null,
            ])),
        ]);
    }

    public function store(Request $request, Bill $bill, DisputeService $disputes): RedirectResponse
    {
        $this->authorize('update', $bill);

        $data = $request->validate([
            'mode' => ['required', Rule::enum(DisputeMode::class)],
            'letter_body' => ['required', 'string', 'max:20000'],
        ]);

        $dispute = $disputes->draft($bill, $request->user());
        $disputes->submit($dispute, DisputeMode::from($data['mode']), $request->user(), $data['letter_body']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $dispute->refresh()->lodged_on !== null ? 'Dispute recorded as sent.' : 'Handed to our team. We\'ll lodge it and keep you posted.',
        ]);

        return to_route('disputes.show', $dispute);
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

    /**
     * @return list<array{value: string, label: string, price: ?string, description: string}>
     */
    private function modes(OrganisationKind $kind): array
    {
        return match ($kind) {
            OrganisationKind::Household => [
                ['value' => DisputeMode::Self->value, 'label' => "I'll send it", 'price' => 'Free',
                    'description' => 'Open the letter in your email app, send it, then tell us. We remind you when a reply is due.'],
                ['value' => DisputeMode::Managed->value, 'label' => 'Handle it for me', 'price' => Money::format(config('auditor.prices.household_dispute')),
                    'description' => 'We lodge it, chase the municipality and escalate to your councillor if nobody replies. We email you a payment link.'],
            ],
            OrganisationKind::Agent => [
                ['value' => DisputeMode::Managed->value, 'label' => 'Lodge it for me', 'price' => 'Included',
                    'description' => 'Part of your subscription: we lodge it, chase it and escalate it.'],
                ['value' => DisputeMode::Self->value, 'label' => "I'll send it", 'price' => 'Included',
                    'description' => 'Send it yourself and tell us. We track the deadline.'],
            ],
            OrganisationKind::Business => [
                ['value' => DisputeMode::SuccessFee->value, 'label' => 'Run this claim', 'price' => null,
                    'description' => 'We lodge and chase the claim. Our fee is a share of what reaches your account, invoiced only once it does.'],
            ],
        };
    }
}
