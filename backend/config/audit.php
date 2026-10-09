<?php

/*
|--------------------------------------------------------------------------
| Audit rule thresholds
|--------------------------------------------------------------------------
|
| Every threshold used by the audit rules (docs/audit-rules.md) lives here
| so it can be tuned without code changes. Rules prefer missing a problem
| over raising a false alarm.
|
*/

return [

    // Billing periods are calendar dates in South Africa; outage overlap is
    // measured against the period in this timezone.
    'timezone' => 'Africa/Johannesburg',

    // Services that are metered and have readings / consumption.
    'metered_services' => ['water', 'electricity'],

    // Only these severities count towards dashboards, deadlines and
    // "potential overcharge" totals. Low findings are information only.
    'counted_severities' => ['medium', 'high'],

    'estimated_reading' => [
        'confidence' => 0.9,
        // medium when the estimated usage is more than 20% above the median
        // of previous actual readings.
        'medium_above_median_ratio' => 0.20,
        'max_actual_compared' => 6,
        // An overcharge is only estimated with at least this many previous
        // actual readings.
        'min_actual_for_overcharge' => 2,
    ],

    'consecutive_estimates' => [
        // This bill plus the bills immediately before it.
        'min_consecutive' => 3,
        'severity' => 'high',
        'confidence' => 0.95,
    ],

    'tariff_mismatch' => [
        // Case-insensitive whole-word keyword matching per customer class.
        // A keyword with "non" in front doesn't count for its class
        // ("Non-Residential" is not residential), so the negated forms are
        // listed under the class they actually mean.
        'classes' => [
            'business' => ['commercial', 'business', 'industrial', 'non-residential', 'non-domestic'],
            'residential' => ['residential', 'domestic', 'household'],
            'bulk' => ['bulk', 'sectional', 'body corporate', 'cluster'],
        ],
        // Residential-type property billed on a business tariff.
        'business_on_residential' => [
            'property_types' => ['residential', 'sectional_title', 'bulk_residential'],
            'severity' => 'high',
            'confidence' => 0.75,
        ],
        // Sectional title / bulk property billed on a single-dwelling
        // residential tariff with no bulk keyword.
        'single_dwelling_on_bulk' => [
            'property_types' => ['sectional_title', 'bulk_residential'],
            'severity' => 'low',
            'confidence' => 0.4,
        ],
    ],

    'outage_charge' => [
        'services' => ['water', 'electricity'],
        // Ignore outages covering less than this fraction of the period.
        'min_fraction' => 0.1,
        // At or above this fraction the supply was off for the whole period.
        'full_period_fraction' => 0.95,
        'full_period' => ['severity' => 'high', 'confidence' => 0.85],
        // Usage above expected × (1 + tolerance) is flagged.
        'tolerance' => 0.15,
        'min_history' => 2,
        'max_history' => 6,
        'over_expected' => ['severity' => 'medium', 'confidence' => 0.65],
        'no_history' => ['severity' => 'low', 'confidence' => 0.4],
    ],

    'consumption_spike' => [
        'min_history' => 3,
        'max_history' => 6,
        // Fires when usage > ratio × median ...
        'ratio' => 2.0,
        // ... and high when usage > high_ratio × median.
        'high_ratio' => 3.0,
        // ... and usage − median is more than this absolute floor.
        'absolute_floor' => [
            'water' => 8,          // kl
            'electricity' => 250,  // kWh
        ],
        'confidence' => 0.7,
    ],

    'arithmetic_mismatch' => [
        // R5.00
        'tolerance_cents' => 500,
        'severity' => 'low',
        'confidence' => 0.5,
    ],

];
