<?php

namespace App\Audit\Checks;

use App\Audit\Data\CheckContext;
use App\Audit\Data\CheckReport;

interface Check
{
    public function run(CheckContext $ctx): CheckReport;
}
