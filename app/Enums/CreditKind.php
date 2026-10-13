<?php

namespace App\Enums;

/**
 * What moved money in or out of an organisation's prepaid credit.
 */
enum CreditKind: string
{
    case TopUp = 'top_up';
    case Job = 'job';
    case Refund = 'refund';
    case Welcome = 'welcome';
    /** Staff correcting a balance, or recording an EFT. */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Top-up',
            self::Job => 'Job',
            self::Refund => 'Refund',
            self::Welcome => 'Welcome credit',
            self::Adjustment => 'Adjustment',
        };
    }
}
