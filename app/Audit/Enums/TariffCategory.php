<?php

namespace App\Audit\Enums;

enum TariffCategory: string
{
    case Residential = 'residential';
    case BulkResidential = 'bulk_residential';
    case Business = 'business';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Residential',
            self::BulkResidential => 'Bulk residential',
            self::Business => 'Business',
            self::Unknown => 'Unknown',
        };
    }
}
