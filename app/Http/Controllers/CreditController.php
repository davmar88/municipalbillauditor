<?php

namespace App\Http\Controllers;

use App\Audit\Money;
use App\Enums\FeeStatus;
use App\Enums\Job;
use App\Models\CreditTransaction;
use App\Models\FeeInvoice;
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
 * Prepaid credit and monthly fees: the balance, what each job costs, the
 * ledger, top-ups and invoices.
 */
class CreditController extends Controller
{
    public function index(Request $request, Wallet $wallet): Response
    {
        $organisation = $request->user()->organisation;
        $used = $wallet->includedChecksUsed($organisation);

        return Inertia::render('credit/index', [
            'balance' => Money::format($wallet->balance($organisation)),
            'prices' => [
                'billCheck' => Money::format(Job::BillCheck->price()),
                'dispute' => Money::format(Job::Dispute->price()),
            ],
            'plan' => $organisation->paysMonthly() ? [
                'monthlyFee' => Money::format((string) $organisation->monthlyFee()),
                'includedChecks' => Wallet::includedChecks(),
                'usedChecks' => $used,
                'card' => $organisation->card_last4 !== null ? [
                    'last4' => $organisation->card_last4,
                    'brand' => Str::title((string) $organisation->card_brand),
                    'expiry' => $organisation->card_expiry,
                ] : null,
            ] : null,
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
            'invoices' => $organisation->feeInvoices()->latest('period_month')->latest('id')->limit(12)->get()->map(fn (FeeInvoice $i) => [
                'id' => $i->id,
                'description' => $i->description,
                'amount' => Money::format($i->amount),
                'status' => $i->status->value,
                'paidOn' => $i->paid_at?->format('j M Y'),
                'payable' => in_array($i->status, [FeeStatus::Due, FeeStatus::Issued], true),
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

    public function payInvoice(Request $request, FeeInvoice $invoice, PaymentService $payments): SymfonyResponse
    {
        abort_unless($invoice->organisation_id === $request->user()->organisation_id, 404);
        abort_if($invoice->isPaid(), 422, 'This invoice is already paid.');

        try {
            return Inertia::location($payments->startInvoicePayment($invoice, $request->user()));
        } catch (PaymentFailed $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }
    }

    /**
     * Where Paystack sends the customer back. It only shows the result: the
     * webhook does the crediting.
     */
    public function callback(Request $request, PaymentService $payments): RedirectResponse
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));

        try {
            $result = $reference !== '' ? $payments->verify($reference) : null;
        } catch (PaymentFailed) {
            $result = null;
        }

        Inertia::flash('toast', $result?->succeeded()
            ? ['type' => 'success', 'message' => 'Payment received, thank you. It shows here as soon as Paystack confirms it.']
            : ['type' => 'error', 'message' => "The payment didn't go through. Nothing was charged."]);

        return to_route('credit.index');
    }
}
