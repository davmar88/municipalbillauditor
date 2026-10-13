<?php

namespace App\Services\Payments;

use App\Audit\Money;
use App\Enums\CreditKind;
use App\Enums\FeeStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\FeeInvoice;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\CustomerNotice;
use App\Services\Billing\Wallet;
use App\Services\CustomerMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Card payments: top-ups and monthly fees.
 *
 * The customer pays on Paystack's checkout page. A payment is applied once,
 * after its amount and currency are checked against our own payment row,
 * by whichever hears from Paystack first: the customer coming back, the
 * answer to a saved-card charge, reconcile() asking about payments still
 * open, or Paystack's signed webhook. The webhook can't be relied on alone:
 * a Paystack account has one, and an account shared with another site
 * sends it there.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Wallet $wallet,
        private readonly CustomerMail $mail,
    ) {}

    /**
     * @throws PaymentFailed
     */
    public function startTopUp(Organisation $organisation, User $user, string $amount): string
    {
        $payment = Payment::create([
            'organisation_id' => $organisation->id,
            'user_id' => $user->id,
            'purpose' => PaymentPurpose::TopUp,
            'amount' => (string) Money::money($amount),
            'currency' => 'ZAR',
            'status' => PaymentStatus::Pending,
        ]);

        return $this->checkout($payment, $user->email);
    }

    /**
     * @throws PaymentFailed
     */
    public function startInvoicePayment(FeeInvoice $invoice, User $user): string
    {
        $payment = Payment::create([
            'organisation_id' => $invoice->organisation_id,
            'user_id' => $user->id,
            'fee_invoice_id' => $invoice->id,
            'purpose' => PaymentPurpose::FeeInvoice,
            'amount' => $invoice->amount,
            'currency' => 'ZAR',
            'status' => PaymentStatus::Pending,
        ]);

        return $this->checkout($payment, $user->email);
    }

    public function verify(string $reference): ChargeResult
    {
        return $this->gateway->verify($reference);
    }

    /**
     * Charge a monthly fee to the organisation's saved card. When there is
     * no card, or the charge fails, the customer gets a link to pay instead.
     * A successful charge is applied straight away; one still processing is
     * picked up by reconcile().
     */
    public function chargeSavedCard(FeeInvoice $invoice): void
    {
        $organisation = $invoice->organisation;
        $code = $organisation->card_authorization;

        if (blank($code) || blank($organisation->card_email)) {
            $this->askToPay($invoice, null);

            return;
        }

        $payment = Payment::create([
            'organisation_id' => $organisation->id,
            'fee_invoice_id' => $invoice->id,
            'purpose' => PaymentPurpose::FeeInvoice,
            'amount' => $invoice->amount,
            'currency' => 'ZAR',
            'status' => PaymentStatus::Pending,
            'saved_card' => true,
        ]);

        try {
            $result = $this->gateway->chargeSavedCard($payment, (string) $code, (string) $organisation->card_email);
        } catch (PaymentFailed $e) {
            $result = null;
            $message = $e->getMessage();
        }

        if ($result?->succeeded()) {
            $this->confirm($result);

            return;
        }

        if ($result !== null && in_array($result->status, ['pending', 'processing', 'ongoing', 'queued'], true)) {
            return;
        }

        $payment->update(['status' => PaymentStatus::Failed, 'gateway_message' => $result?->message ?? $message ?? null]);
        $this->askToPay($invoice, $organisation->card_last4);
    }

    /**
     * Asks Paystack about card payments still open, and applies the ones
     * that went through: a top-up whose customer never came back, or a
     * saved-card charge that was still processing. New payments are checked
     * on every run, ones older than two hours on the first run of each hour,
     * and after two days an unpaid one is closed. Runs every five minutes.
     *
     * @return array{applied: int, closed: int}
     */
    public function reconcile(): array
    {
        $now = CarbonImmutable::now();
        $applied = 0;
        $closed = 0;

        $open = Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', $now->subMinutes(2))
            ->orderBy('id')
            ->get();

        foreach ($open as $payment) {
            $expired = $payment->created_at->lte($now->subDays(2));

            if (! $expired && $payment->created_at->lte($now->subHours(2)) && $now->minute >= 5) {
                continue;
            }

            try {
                $result = $this->gateway->verify($payment->reference);
            } catch (PaymentFailed $e) {
                if ($expired) {
                    Log::warning('Closing a payment Paystack could not tell us about', ['reference' => $payment->reference, 'error' => $e->getMessage()]);
                    $payment->update(['status' => PaymentStatus::Failed, 'gateway_message' => $e->getMessage()]);
                    $closed++;
                }

                continue;
            }

            if ($result->succeeded() && $this->confirm($result)) {
                $applied++;
            } elseif ($expired) {
                // Unpaid, or paid with an amount confirm() refused and logged.
                $payment->update(['status' => PaymentStatus::Failed, 'gateway_message' => $result->succeeded()
                    ? 'Paid, but not the amount or currency we asked for: see the log.'
                    : ($result->message ?? 'No payment within two days.')]);
                $closed++;
            }
        }

        return ['applied' => $applied, 'closed' => $closed];
    }

    /**
     * Paystack says a charge succeeded: credit the top-up or mark the invoice
     * paid, once. Returns false when the payment isn't ours or doesn't match.
     */
    public function confirm(ChargeResult $result): bool
    {
        if (! $result->succeeded()) {
            return false;
        }

        return DB::transaction(function () use ($result) {
            $payment = Payment::query()->where('reference', $result->reference)->lockForUpdate()->first();

            if ($payment === null) {
                Log::warning('Paystack confirmed a payment we have no record of', ['reference' => $result->reference]);

                return false;
            }

            if ($payment->status === PaymentStatus::Success) {
                return true;
            }

            if ($result->currency !== 'ZAR' || $result->amountInCents !== $payment->amountInCents()) {
                Log::error('Paystack payment does not match our record', [
                    'reference' => $payment->reference,
                    'expected' => $payment->amountInCents(),
                    'paid' => $result->amountInCents,
                    'currency' => $result->currency,
                ]);

                return false;
            }

            $payment->update(['status' => PaymentStatus::Success, 'paid_at' => now(), 'gateway_message' => $result->message]);

            if ($payment->purpose === PaymentPurpose::TopUp) {
                $this->wallet->credit(
                    $payment->organisation,
                    CreditKind::TopUp,
                    $payment->amount,
                    "payment:{$payment->reference}",
                    'Card top-up',
                    $payment,
                    $payment->user,
                );

                return true;
            }

            $invoice = $payment->feeInvoice;

            if ($invoice !== null && ! $invoice->isPaid()) {
                $invoice->update(['status' => FeeStatus::Paid, 'paid_at' => now()]);
            }

            if ($result->card !== null) {
                $payment->organisation->forceFill([
                    'card_authorization' => $result->card->authorizationCode,
                    'card_last4' => $result->card->last4,
                    'card_brand' => $result->card->brand,
                    'card_expiry' => $result->card->expiry,
                    'card_email' => $result->card->email !== '' ? $result->card->email : $payment->user?->email,
                    'card_saved_at' => now(),
                ])->save();
            }

            return true;
        });
    }

    /**
     * @throws PaymentFailed
     */
    private function checkout(Payment $payment, string $email): string
    {
        try {
            return $this->gateway->checkout($payment, $email, route('credit.callback'));
        } catch (PaymentFailed $e) {
            $payment->update(['status' => PaymentStatus::Failed, 'gateway_message' => $e->getMessage()]);

            throw $e;
        }
    }

    private function askToPay(FeeInvoice $invoice, ?string $failedCard): void
    {
        $this->mail->send($invoice->organisation, new CustomerNotice(
            kind: 'invoice_due',
            subject: 'Your '.$invoice->period_month?->format('F Y').' fee of '.Money::format($invoice->amount).' is due',
            lines: [
                $failedCard !== null
                    ? "We couldn't charge your card ending {$failedCard} for this month's fee of ".Money::format($invoice->amount).'.'
                    : "This month's fee of ".Money::format($invoice->amount).' is due.',
                'Pay it by card in a minute. After that we charge the same card each month, and you can remove it in your settings.',
            ],
            actionText: 'Pay now',
            actionUrl: route('credit.index'),
        ));
    }
}
