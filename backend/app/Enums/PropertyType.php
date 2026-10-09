<?php

namespace App\Enums;

enum PropertyType: string
{
    use EnumValues;

    case Residential = 'residential';
    case SectionalTitle = 'sectional_title';
    case BulkResidential = 'bulk_residential';
    case Commercial = 'commercial';
    case Industrial = 'industrial';
    case Agricultural = 'agricultural';
    case VacantLand = 'vacant_land';

    /**
     * Plain-language description used in explanations and letters.
     */
    public function phrase(): string
    {
        return match ($this) {
            self::Residential => 'a residential home',
            self::SectionalTitle => 'a sectional title unit',
            self::BulkResidential => 'a residential complex billed in bulk',
            self::Commercial => 'a commercial property',
            self::Industrial => 'an industrial property',
            self::Agricultural => 'an agricultural property',
            self::VacantLand => 'vacant land',
        };
    }
}
