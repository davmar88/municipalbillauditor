<?php

namespace App\Http\Controllers\Staff;

use App\Audit\Deadlines\Stage;
use App\Audit\Money;
use App\Enums\BillStatus;
use App\Enums\CreditKind;
use App\Enums\DisputeEventKind;
use App\Enums\DisputeMode;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DisputePresenter;
use App\Http\Requests\RecordDisputeEventRequest;
use App\Jobs\SendDispute;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Organisation;
use App\Services\Billing\MarginReport;
use App\Services\Billing\Wallet;
use App\Services\Disputes\RecipientFinder;
use App\Services\DisputeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The team's work queue: managed disputes to lodge, disputes needing an
 * escalation, and bills waiting for their details.
 */
class StaffController extends Controller
{
    public function index(DisputeService $disputes, RecipientFinder $recipients): Response
    {
        $toLodge = Dispute::query()
            ->whereNotNull('submitted_on')
            ->whereNull('lodged_on')
            ->with(['property.municipality', 'bill', 'findings', 'organisation'])
            ->oldest('submitted_on')
            ->get();

        $open = Dispute::query()
            ->whereNotNull('lodged_on')
            ->whereNull('credit_on')
            ->whereNull('closed_on')
            ->where('mode', '!=', DisputeMode::Self)
            ->with(['property.municipality', 'bill.user', 'findings', 'organisation.users'])
            ->get()
            ->filter(fn (Dispute $d) => $d->stage()->needsAction());

        $bills = Bill::query()
            ->where(fn ($q) => $q->whereIn('status', [BillStatus::NeedsEntry, BillStatus::NeedsProperty])->orWhere('needs_review', true))
            ->with('organisation')
            ->latest()
            ->limit(50)
            ->get();

        $row = fn (Dispute $d) => [
            ...DisputePresenter::summary($d),
            'organisation' => $d->organisation->name,
            'letterSubject' => $d->letter_subject,
            'letterBody' => $d->letter_body,
            'billingEmail' => $d->property->municipality?->billing_email,
            'billUrl' => $d->bill->file_path ? route('staff.bills.file', $d->bill) : null,
        ];

        return Inertia::render('staff/index', [
            'toLodge' => $toLodge->map(fn (Dispute $d) => [
                ...$row($d),
                'heldReason' => $d->held_reason,
                'recipient' => $recipients->for($d)['email'] ?? null,
            ])->values(),
            'needsEscalation' => $open->map(fn (Dispute $d) => [
                ...$row($d),
                'councillorLetter' => $this->councillorLetter($d, $disputes),
            ])->values(),
            'eventKinds' => RecordDisputeEventRequest::options(),
            'organisations' => Organisation::query()->orderBy('name')->get()->map(fn (Organisation $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'kind' => $o->kind->label(),
                'balance' => Money::format($o->credit_balance),
            ])->values(),
            'bills' => $bills->map(fn (Bill $b) => [
                'id' => $b->id,
                'organisation' => $b->organisation->name,
                'status' => $b->status->label(),
                'note' => $b->review_note,
                'received' => $b->created_at->format('j M Y'),
            ])->values(),
        ]);
    }

    /**
     * Send a held dispute, once the municipality's address is known.
     */
    public function send(Dispute $dispute): RedirectResponse
    {
        abort_if($dispute->submitted_on === null || $dispute->lodged_on !== null, 422, 'Only approved, unsent disputes can be sent.');

        $dispute->update(['held_reason' => null]);
        SendDispute::dispatch($dispute);
        $dispute->refresh();

        Inertia::flash('toast', match (true) {
            $dispute->lodged_on !== null => ['type' => 'success', 'message' => "Sent to {$dispute->sent_to}."],
            $dispute->held_reason !== null => ['type' => 'error', 'message' => 'Still held: there is no address or no permission from the customer.'],
            default => ['type' => 'success', 'message' => 'Sending it now.'],
        });

        return back();
    }

    public function lodge(Request $request, Dispute $dispute, DisputeService $disputes): RedirectResponse
    {
        abort_if($dispute->lodged_on !== null, 422, 'Already lodged.');

        $data = $request->validate([
            'lodged_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
        ]);

        $disputes->lodge($dispute, CarbonImmutable::parse($data['lodged_on']), $data['reference'] ?? null, $request->user(), 'Lodged by our team');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Marked as lodged.']);

        return back();
    }

    /**
     * The letter our team sends to the ward councillor, signed as the
     * customer who sent the bill.
     *
     * @return array{subject: string, body: string}|null
     */
    private function councillorLetter(Dispute $dispute, DisputeService $disputes): ?array
    {
        $customer = $dispute->bill->user ?? $dispute->organisation->users->first();

        if ($customer === null || $dispute->stage() !== Stage::ReplyOverdue) {
            return null;
        }

        $letter = $disputes->councillorLetter($dispute, $customer);

        return $letter ? ['subject' => $letter->subject, 'body' => $letter->body] : null;
    }

    /**
     * Our team records what happened on the disputes it manages.
     */
    public function record(RecordDisputeEventRequest $request, Dispute $dispute, DisputeService $disputes): RedirectResponse
    {
        abort_if($dispute->mode === DisputeMode::Self, 403, 'The customer manages this dispute.');

        $disputes->record(
            $dispute,
            DisputeEventKind::from($request->validated('kind')),
            CarbonImmutable::parse($request->validated('happened_on')),
            $request->details(),
            $request->user(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recorded.']);

        return back();
    }

    /**
     * The month's revenue against what Claude cost us.
     */
    public function margin(Request $request, MarginReport $report): Response
    {
        $month = $request->query('month');
        $month = is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)
            ? CarbonImmutable::createFromFormat('!Y-m', $month)
            : CarbonImmutable::now();

        return Inertia::render('staff/margin', [
            'report' => $report->forMonth($month),
            'months' => MarginReport::months(),
        ]);
    }

    /**
     * A customer paid by EFT: add it to their credit. The bank reference
     * makes it count once, however often the form is sent.
     */
    public function recordEft(Request $request, Organisation $organisation, Wallet $wallet): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:100000'],
            'bank_reference' => ['required', 'string', 'max:60'],
        ]);

        $reference = Str::of($data['bank_reference'])->lower()->replaceMatches('/\s+/', '')->limit(60, '')->value();
        $transaction = $wallet->credit(
            $organisation,
            CreditKind::TopUp,
            $data['amount'],
            "eft:{$organisation->id}:{$reference}",
            "EFT top-up, bank reference {$data['bank_reference']}",
            by: $request->user(),
        );

        Inertia::flash('toast', $transaction->wasRecentlyCreated
            ? ['type' => 'success', 'message' => Money::format($data['amount'])." added to {$organisation->name}'s credit."]
            : ['type' => 'error', 'message' => 'That bank reference was already recorded for this account.']);

        return back();
    }

    /**
     * Staff can open a bill's file to lodge or type it in.
     */
    public function billFile(Bill $bill): StreamedResponse
    {
        abort_if($bill->file_path === null, 404);

        return Storage::disk(config('auditor.bill_disk'))->response($bill->file_path, $bill->file_name, [
            'Content-Type' => $bill->file_mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
