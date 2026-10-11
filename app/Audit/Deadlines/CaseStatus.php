<?php

namespace App\Audit\Deadlines;

use DateTimeImmutable;

final readonly class CaseStatus
{
    public function __construct(
        public Stage $stage,
        public string $headline,
        public ?DateTimeImmutable $nextDate = null,
        public string $countdownLabel = '',
        public ?int $daysLeft = null,
        public ?float $progress = null,
        public string $note = '',
    ) {}
}
