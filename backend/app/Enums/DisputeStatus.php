<?php

namespace App\Enums;

enum DisputeStatus: string
{
    use EnumValues;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    /**
     * Lodged with the municipality and waiting on an outcome.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Submitted, self::Acknowledged, self::Escalated];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function isFinal(): bool
    {
        return $this === self::Resolved || $this === self::Rejected;
    }
}
