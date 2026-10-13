<?php

namespace App\Audit\Enums;

enum ReadingType: string
{
    case Actual = 'actual';
    case Estimated = 'estimated';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Actual => 'Actual',
            self::Estimated => 'Estimated',
            self::Unknown => 'Not shown',
        };
    }
}
