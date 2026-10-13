<?php

namespace App\Services\Billing;

use App\Audit\Money;
use App\Enums\Job;
use RuntimeException;

/**
 * Not enough prepaid credit for a job. The message is safe to show.
 */
class InsufficientCredit extends RuntimeException
{
    public function __construct(
        public readonly Job $job,
        public readonly string $price,
        public readonly string $balance,
    ) {
        parent::__construct(
            'A '.strtolower($job->label()).' costs '.Money::format($price).' and your credit is '.Money::format($balance).'. Top up to carry on.',
        );
    }
}
