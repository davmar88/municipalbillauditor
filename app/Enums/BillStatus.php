<?php

namespace App\Enums;

enum BillStatus: string
{
    /** Stored, waiting to be read. */
    case Received = 'received';
    /** Not enough prepaid credit: it is checked as soon as there is. */
    case WaitingForCredit = 'waiting_for_credit';
    /** Being read by the bill reader. */
    case Reading = 'reading';
    /** Couldn't be read automatically: someone types in the details. */
    case NeedsEntry = 'needs_entry';
    /** Read, but we don't know which property it belongs to. */
    case NeedsProperty = 'needs_property';
    /** Read and checked. */
    case Checked = 'checked';

    public function label(): string
    {
        return match ($this) {
            self::Received, self::Reading => 'Reading your bill',
            self::WaitingForCredit => 'Waiting for credit',
            self::NeedsEntry => 'Needs details',
            self::NeedsProperty => 'Choose a property',
            self::Checked => 'Checked',
        };
    }
}
