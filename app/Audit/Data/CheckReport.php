<?php

namespace App\Audit\Data;

final class CheckReport
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $passed  What was checked and looks right.
     * @param  list<string>  $skipped  What couldn't be checked, and why.
     */
    public function __construct(
        public array $findings = [],
        public array $passed = [],
        public array $skipped = [],
    ) {}

    public function merge(self $other): void
    {
        array_push($this->findings, ...$other->findings);
        array_push($this->passed, ...$other->passed);
        array_push($this->skipped, ...$other->skipped);
    }
}
