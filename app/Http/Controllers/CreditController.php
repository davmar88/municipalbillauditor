<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\Job;
use App\Models\CreditTransaction;
use App\Models\Payment;
use App\Services\Billing\Wallet;
use App\Services\Payments\PaymentFailed;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Prepaid credit: the balance, what each job costs, the ledger and
 * top-ups.
 */
class CreditController extends Controller
{
    public function index(Request $request, Wallet $wallet): Response
    {
        $organisation = $request->user()->organisation;

        return Inertia::render('credit/index', [
            'balance' => Money::format($wallet->balance($organisation)),
            'prices' => [
                'billCheck' => Money::format(Job::BillCheck->price()),
                'dispute' => Money::format(Job::Dispute->price()),
            ],
            'packs' => array_map(fn (string $amount) => [
                'value' => (string) Money::money($amount),
                'label' => Str::replaceLast('.00', '', Money::format($amount)),
            ], config('auditor.prices.top_ups')),
            'cardPayments' => filled(config('services.paystack.secret_key')),
            'transactions' => $organisation->creditTransactions()->latest('id')->limit(50)->get()->map(fn (CreditTransaction $t) => [
                'id' => $t->id,
                'on' => $t->created_at->format('j M Y'),
                'description' => $t->description,
                'amount' => Money::format($t->amount, signed: true),
                'balance' => Money::format($t->balance_after),
                'kind' => $t->kind->value,
            ])->values(),
        ]);
    }

    public function topUp(Request $request, PaymentService $payments): SymfonyResponse
    {
        $amount = $request->validate([
            'amount' => ['required', Rule::in(array_map(fn (string $a) => (string) Money::money($a), config('auditor.prices.top_ups')))],
        ], ['amount.in' => 'Choose one of the top-up amounts.'])['amount'];

        try {
            return Inertia::location($payments->startTopUp($request->user()->organisation, $request->user(), $amount));
        } catch (PaymentFailed $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }
    }

    /**
     * Where Paystack sends the customer back. We ask Paystack how the
     * payment went and, when it succeeded, apply it through the same
     * once-only path as the webhook: the webhook may not reach us, and
     * whichever arrives second changes nothing.
     */
    public function callback(Request $request, PaymentService $payments): RedirectResponse
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));

        try {
            $result = $reference !== '' ? $payments->verify($reference) : null;
        } catch (PaymentFailed) {
            $result = null;
        }

        $applied = $result?->succeeded() && $this->ours($request, $result->reference) && $payments->confirm($result);

        Inertia::flash('toast', match (true) {
            $applied => ['type' => 'success', 'message' => 'Payment received, thank you.'],
            (bool) $result?->succeeded() => ['type' => 'success', 'message' => 'Payment received. It shows here as soon as Paystack confirms it.'],
            default => ['type' => 'error', 'message' => "The payment didn't go through. Nothing was charged."],
        });

        return to_route('credit.index');
    }

    /** Only the account that started a payment applies it from here. */
    private function ours(Request $request, string $reference): bool
    {
        return Payment::query()
            ->where('reference', $reference)
            ->where('organisation_id', $request->user()->organisation_id)
            ->exists();
    }
}
