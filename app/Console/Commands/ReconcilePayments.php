<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every five minutes. Asks Paystack about card payments still open,
 * so a payment is applied even when the customer never comes back and the
 * webhook goes to another site sharing the Paystack account.
 */
#[Signature('payments:reconcile')]
#[Description('Check card payments that are still open with Paystack')]
class ReconcilePayments extends Command
{
    public function handle(PaymentService $payments): int
    {
        ['applied' => $applied, 'closed' => $closed] = $payments->reconcile();

        $this->info("{$applied} applied, {$closed} closed.");

        return self::SUCCESS;
    }
}
