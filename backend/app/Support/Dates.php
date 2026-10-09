<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Date display formats. format() on DateTime is locale independent.
 */
final class Dates
{
    /**
     * "25 Sep 2026": used in the UI-facing texts (explanations, labels).
     */
    public static function short(?CarbonInterface $date): ?string
    {
        return $date?->format('j M Y');
    }

    /**
     * "25 September 2026": used in formal dispute letters.
     */
    public static function long(?CarbonInterface $date): ?string
    {
        return $date?->format('j F Y');
    }
}
