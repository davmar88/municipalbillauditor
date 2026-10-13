<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /** Sent to Paystack, no answer yet. */
    case Pending = 'pending';
    /** Confirmed by Paystack's webhook, and credited or applied. */
    case Success = 'success';
    case Failed = 'failed';
}
