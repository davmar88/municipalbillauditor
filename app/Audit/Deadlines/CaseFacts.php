<?php

namespace App\Audit\Deadlines;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * What has happened on a dispute so far.
 */
final readonly class CaseFacts
{
    public function __construct(
        public ?DateTimeImmutable $billDate,
        public ?DateTimeImmutable $checkedOn = null,
        public string $findingsSummary = '',
        public ?DateTimeImmutable $lodgedOn = null,
        public ?DateTimeImmutable $acknowledgedOn = null,
        public string $reference = '',
        public ?DateTimeImmutable $replyReceivedOn = null,
        public ?DateTimeImmutable $escalatedCouncillorOn = null,
        public ?DateTimeImmutable $escalatedPublicProtectorOn = null,
        public ?DateTimeImmutable $tariffFixedOn = null,
        public ?BigDecimal $monthlySaving = null,
        public ?DateTimeImmutable $creditOn = null,
        public ?BigDecimal $creditAmount = null,
        public ?DateTimeImmutable $closedOn = null,
        public bool $managed = false,
    ) {}
}
