<?php

namespace App\Enums;

use App\Audit\Money;

/**
 * The two things a household pays for.
 */
enum Job: string
{
    /** Reading a bill and running the four checks. */
    case BillCheck = 'bill_check';
    /** Drafting a dispute, emailing it, reading replies, chasing and escalating. */
    case Dispute = 'dispute';

    public function label(): string
    {
        return match ($this) {
            self::BillCheck => 'Bill check',
            self::Dispute => 'Dispute',
        };
    }

    /** The household price, in rand. */
    public function price(): string
    {
        return (string) Money::money((string) config("auditor.prices.jobs.{$this->value}"));
    }
}
