<?php

namespace App\Audit\Enums;

enum Confidence: string
{
    case Likely = 'likely';
    case Possible = 'possible';
    case Question = 'question';

    public function label(): string
    {
        return match ($this) {
            self::Likely => 'Likely error',
            self::Possible => 'Worth checking',
            self::Question => 'Needs your answer',
        };
    }

    /** Findings are listed most certain first. */
    public function order(): int
    {
        return match ($this) {
            self::Likely => 0,
            self::Question => 1,
            self::Possible => 2,
        };
    }
}
