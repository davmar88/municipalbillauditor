<?php

namespace App\Enums;

enum FeeKind: string
{
    case ManagedDispute = 'managed_dispute';
    case BackRefund = 'back_refund';
    case MonthlySaving = 'monthly_saving';
}
