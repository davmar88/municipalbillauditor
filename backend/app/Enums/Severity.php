<?php

namespace App\Enums;

enum Severity: string
{
    use EnumValues;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Higher is more severe; used for ordering findings.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    /**
     * Medium and high findings count towards dashboards, deadlines and
     * potential overcharge totals; low findings are information only.
     *
     * @return list<string>
     */
    public static function counted(): array
    {
        return config('audit.counted_severities', [self::Medium->value, self::High->value]);
    }
}
