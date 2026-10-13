<?php

namespace App\Audit;

use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Enums\TariffCategory;

/**
 * Turns the words printed on a bill into the engine's categories.
 */
final class Normalize
{
    private const BUSINESS_WORDS = ['business', 'commercial', 'industrial', 'non-residential', 'non residential'];

    private const RESIDENTIAL_WORDS = ['resid', 'domestic', 'household'];

    public static function tariff(?string $label): TariffCategory
    {
        $text = mb_strtolower(trim((string) $label));

        if ($text === '') {
            return TariffCategory::Unknown;
        }

        // Business words first: "non-residential" contains "resid".
        if (self::containsAny($text, self::BUSINESS_WORDS)) {
            return TariffCategory::Business;
        }

        if (str_contains($text, 'bulk') && self::containsAny($text, self::RESIDENTIAL_WORDS)) {
            return TariffCategory::BulkResidential;
        }

        if (self::containsAny($text, self::RESIDENTIAL_WORDS)) {
            return TariffCategory::Residential;
        }

        return TariffCategory::Unknown;
    }

    public static function service(?string $text): Service
    {
        $value = mb_strtolower(trim((string) $text));

        return match (true) {
            $value === '' => Service::Other,
            in_array($value, array_column(Service::cases(), 'value'), true) => Service::from($value),
            self::containsAny($value, ['sewer', 'sanitation', 'effluent', 'waste water', 'wastewater']) => Service::Sanitation,
            str_contains($value, 'water') => Service::Water,
            self::containsAny($value, ['electric', 'power', 'kwh']) => Service::Electricity,
            self::containsAny($value, ['refuse', 'waste', 'rubbish']) => Service::Refuse,
            self::containsAny($value, ['rate', 'property tax']) => Service::Rates,
            default => Service::Other,
        };
    }

    public static function readingType(?string $text): ReadingType
    {
        $value = mb_strtolower(trim((string) $text));

        return match (true) {
            in_array($value, ['e', 'est', 'estimate', 'estimated', 'deemed', 'interim'], true),
            str_starts_with($value, 'estim') => ReadingType::Estimated,
            in_array($value, ['a', 'act', 'actual', 'read', 'measured'], true),
            str_starts_with($value, 'actual') => ReadingType::Actual,
            default => ReadingType::Unknown,
        };
    }

    /**
     * @param  list<string>  $words
     */
    private static function containsAny(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
