<?php

namespace App\Enums;

enum DisputeMode: string
{
    /**
     * The customer sent the letter themselves. No longer offered: kept for
     * disputes sent that way before every dispute was emailed by us.
     */
    case Self = 'self';
    /** We email it, read the replies, chase and escalate. */
    case Managed = 'managed';

    public function isManagedByUs(): bool
    {
        return $this === self::Managed;
    }
}
