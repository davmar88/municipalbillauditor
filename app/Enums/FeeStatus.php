<?php

namespace App\Enums;

enum FeeStatus: string
{
    /** Owed, not yet sent. */
    case Due = 'due';
    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';
}
