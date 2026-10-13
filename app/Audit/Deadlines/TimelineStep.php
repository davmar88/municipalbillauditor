<?php

namespace App\Audit\Deadlines;

use DateTimeImmutable;

final readonly class TimelineStep
{
    /**
     * @param  string  $state  done, current, upcoming or if_needed
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $detail,
        public ?DateTimeImmutable $on,
        public string $state,
    ) {}
}
