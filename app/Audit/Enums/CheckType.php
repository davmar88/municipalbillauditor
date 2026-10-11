<?php

namespace App\Audit\Enums;

enum CheckType: string
{
    case EstimatedReading = 'estimated_reading';
    case WrongTariff = 'wrong_tariff';
    case OutageCharge = 'outage_charge';
    case UsageSpike = 'usage_spike';

    public function label(): string
    {
        return match ($this) {
            self::EstimatedReading => 'Estimated readings',
            self::WrongTariff => 'Wrong tariff category',
            self::OutageCharge => 'Charges during outages',
            self::UsageSpike => 'Spikes against history',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::EstimatedReading => 'Estimated reading',
            self::WrongTariff => 'Wrong tariff',
            self::OutageCharge => 'Outage charge',
            self::UsageSpike => 'Spike',
        };
    }
}
