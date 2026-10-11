<?php

namespace App\Enums;

enum DisputeMode: string
{
    /** The customer sends the letter. Free. */
    case Self = 'self';
    /** We lodge, chase and escalate for a flat fee (households). */
    case Managed = 'managed';
    /** We run the claim for a share of what is recovered (businesses). */
    case SuccessFee = 'success_fee';

    public function isManagedByUs(): bool
    {
        return $this !== self::Self;
    }
}
