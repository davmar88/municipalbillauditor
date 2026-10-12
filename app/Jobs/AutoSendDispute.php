<?php

namespace App\Jobs;

use App\Models\Bill;
use App\Services\Disputes\DisputeApproval;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * After a bill is checked: send its dispute by itself, when the customer
 * asked for that. Runs on its own so that nothing here can undo the check.
 */
class AutoSendDispute implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Bill $bill) {}

    public function handle(DisputeApproval $approval): void
    {
        try {
            $approval->autoSend($this->bill);
        } catch (Throwable $e) {
            // The bill stays checked and the customer can still approve the
            // dispute themselves.
            report($e);
        }
    }
}
