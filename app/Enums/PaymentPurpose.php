<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case TopUp = 'top_up';
    case FeeInvoice = 'fee_invoice';
}
