<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Services\BillChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs after credit is added: bills that were waiting for it are checked.
 */
class ResumeWaitingWork implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $organisationId) {}

    public function handle(BillChecker $checker): void
    {
        $organisation = Organisation::find($this->organisationId);

        if ($organisation !== null) {
            $checker->resume($organisation);
        }
    }
}
