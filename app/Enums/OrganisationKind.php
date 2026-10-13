<?php

namespace App\Enums;

enum OrganisationKind: string
{
    case Household = 'household';
    case Agent = 'agent';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Household => 'Household',
            self::Agent => 'Landlord or managing agent',
            self::Business => 'Business or body corporate',
        };
    }
}
