<?php

namespace App\Services;

use App\Enums\BillStatus;
use App\Enums\Job;
use App\Jobs\ReadBill;
use App\Models\Bill;
use App\Models\Organisation;
use App\Services\Billing\InsufficientCredit;
use App\Services\Billing\Wallet;

/**
 * A bill check as a paid job: charge for it once, then read and check the
 * bill. Without enough credit the bill waits, and is checked by itself
 * after the next top-up.
 *
 * Re-checking a bill that was already paid for (after an answer, a
 * correction or a new property) is free and goes straight to AuditService.
 */
class BillChecker
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly AuditService $audit,
        private readonly CustomerMail $mail,
    ) {}

    /**
     * Pay for the bill's check. False when there isn't enough credit: the
     * bill is then parked and the customer told.
     */
    public function pay(Bill $bill): bool
    {
        try {
            $this->wallet->charge($bill->organisation, Job::BillCheck, $bill, $bill->user);
        } catch (InsufficientCredit $e) {
            $this->park($bill, $e);

            return false;
        }

        return true;
    }

    /**
     * Pay for and check a bill whose details are known, such as one typed
     * in by hand.
     */
    public function check(Bill $bill): bool
    {
        if (! $this->pay($bill)) {
            return false;
        }

        $this->audit->run($bill);
        $this->mail->billChecked($bill->refresh());

        return true;
    }

    /**
     * Give the check back when the bill couldn't be read.
     */
    public function refund(Bill $bill, string $reason): void
    {
        $this->wallet->refund($bill->organisation, Job::BillCheck, $bill, $reason);
    }

    /**
     * After a top-up: check the bills that were waiting, oldest first, for
     * as long as the credit lasts.
     */
    public function resume(Organisation $organisation): int
    {
        $resumed = 0;

        $waiting = $organisation->bills()
            ->where('status', BillStatus::WaitingForCredit)
            ->orderBy('waiting_since')
            ->orderBy('id')
            ->get();

        foreach ($waiting as $bill) {
            if (! $this->wallet->quote($organisation, Job::BillCheck)->affordable) {
                break;
            }

            $resumed++;

            // Typed-in bills are checked; uploaded ones are read first.
            if ($bill->read_method === 'manual' && $bill->property_id !== null && $bill->bill_date !== null) {
                $bill->update(['status' => BillStatus::Received, 'waiting_since' => null]);
                $this->check($bill);

                continue;
            }

            $bill->update(['status' => BillStatus::Received, 'waiting_since' => null]);
            ReadBill::dispatch($bill);
        }

        return $resumed;
    }

    private function park(Bill $bill, InsufficientCredit $reason): void
    {
        $alreadyWaiting = $bill->status === BillStatus::WaitingForCredit;

        $bill->update([
            'status' => BillStatus::WaitingForCredit,
            'waiting_since' => $bill->waiting_since ?? now(),
        ]);

        if (! $alreadyWaiting) {
            $this->mail->waitingForCredit($bill, $reason);
        }
    }
}
