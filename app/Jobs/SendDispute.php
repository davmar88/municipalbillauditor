<?php

namespace App\Jobs;

use App\Models\Dispute;
use App\Services\Disputes\DisputeMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Emails an approved dispute, retrying if the mail server is unreachable.
 * When the last try fails the dispute is held for our team.
 */
class SendDispute implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Dispute $dispute) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(DisputeMailer $mailer): void
    {
        $mailer->send($this->dispute);
    }

    public function failed(?Throwable $exception): void
    {
        app(DisputeMailer::class)->sendFailed($this->dispute->refresh(), $exception);
    }
}
