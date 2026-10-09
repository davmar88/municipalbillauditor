<?php

namespace App\Http\Requests\Concerns;

/**
 * Rules for a timestamp that records something that already happened (a
 * dispute being sent, the municipality replying). A future time would push
 * deadlines out and put the timeline in an impossible order.
 */
final class PastTimestampRules
{
    /** Allowance for a device clock that runs slightly fast. */
    public const CLOCK_SKEW_MINUTES = 5;

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'date',
            'before_or_equal:'.now('UTC')->addMinutes(self::CLOCK_SKEW_MINUTES)->toIso8601String(),
        ];
    }
}
