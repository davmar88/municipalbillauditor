<?php

namespace App\Audit\Data;

use App\Audit\Enums\Service;
use DateTimeImmutable;

final readonly class OutageWindow
{
    public function __construct(
        public Service $service,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public string $area = '',
        public string $source = '',
    ) {}
}
