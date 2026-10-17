<?php

namespace App\Enums;

enum FactionRole: string
{
    case Leader = 'leader';
    case Council = 'council';
    case Member = 'member';
    case Recruit = 'recruit';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Leaders and the council decide the faction's laws when it rules. */
    public function decides(): bool
    {
        return $this === self::Leader || $this === self::Council;
    }
}
