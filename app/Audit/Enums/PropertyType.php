<?php

namespace App\Audit\Enums;

enum PropertyType: string
{
    case Residential = 'residential';
    case SectionalUnit = 'sectional_unit';
    case BulkResidential = 'bulk_residential';
    case Business = 'business';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Home',
            self::SectionalUnit => 'Unit in a sectional title scheme',
            self::BulkResidential => 'Residential scheme on one account',
            self::Business => 'Business',
            self::Unknown => 'Not set',
        };
    }

    /** As it reads in a sentence: "This is a residential property". */
    public function description(): string
    {
        return match ($this) {
            self::Residential => 'a residential property',
            self::SectionalUnit => 'a residential unit in a sectional title scheme',
            self::BulkResidential => 'a residential scheme on one bulk account',
            self::Business => 'a business property',
            self::Unknown => 'not known yet',
        };
    }

    public function isResidential(): bool
    {
        return in_array($this, [self::Residential, self::SectionalUnit, self::BulkResidential], true);
    }

    /** The tariff a property of this type should be billed on. */
    public function expectedTariff(): TariffCategory
    {
        return match ($this) {
            self::Residential, self::SectionalUnit => TariffCategory::Residential,
            self::BulkResidential => TariffCategory::BulkResidential,
            self::Business => TariffCategory::Business,
            self::Unknown => TariffCategory::Unknown,
        };
    }

    /** @return list<self> */
    public static function known(): array
    {
        return [self::Residential, self::SectionalUnit, self::BulkResidential, self::Business];
    }
}
