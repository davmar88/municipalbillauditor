<?php

namespace App\Enums;

enum FindingRule: string
{
    use EnumValues;

    case EstimatedReading = 'estimated_reading';
    case ConsecutiveEstimates = 'consecutive_estimates';
    case TariffMismatch = 'tariff_mismatch';
    case OutageCharge = 'outage_charge';
    case ConsumptionSpike = 'consumption_spike';
    case ArithmeticMismatch = 'arithmetic_mismatch';
}
