<?php

namespace App\Enums;

enum DeadlineType: string
{
    use EnumValues;

    case LodgeDispute = 'lodge_dispute';
    case Escalate = 'escalate';
}
