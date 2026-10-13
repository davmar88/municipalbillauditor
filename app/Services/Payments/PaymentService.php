<?php

namespace App\Services\Payments;

use App\Audit\Money;
use App\Enums\CreditKind;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Card top-ups: the only way money comes in. Every account pays as it goes,
 * so there are no fees to invoice and no cards kept on file.
 *
 * The customer pays on Paystack's checkout page. A payment is applied once,
 * after its amount and currency are checked against our own payment row,
 * by whichever hears from Paystack first: the customer coming back,
 * reconcile() asking about payments still open, or Paystack's signed
 * webhook. The webhook can't be relied on alone:
 * a Paystack account has one, and an account shared with another site
 * sends it there.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Wallet $wallet,
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

    public function verify(string $reference): ChargeResult
    {
        return $this->gateway->verify($reference);
    }

    /**
     * Asks Paystack about card payments still open, and applies the ones
     * that went through: a top-up whose customer never came back. New payments are checked
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
     * Paystack says a charge succeeded: credit the top-up, once. Returns
     * false when the payment isn't ours or doesn't match.
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
}
