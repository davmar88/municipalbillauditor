<?php

namespace App\Enums;

enum FeeKind: string
{
    /** An agent's or business's flat fee for one calendar month. */
    case MonthlyFee = 'monthly_fee';
}
