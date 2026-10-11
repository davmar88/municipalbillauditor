<?php

namespace App\Enums;

enum FindingState: string
{
    case Open = 'open';
    /** The customer confirmed the facts, for example that the water was off. */
    case Confirmed = 'confirmed';
    /** The customer told us it is not an error. */
    case Dismissed = 'dismissed';
}
