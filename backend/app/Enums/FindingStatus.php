<?php

namespace App\Enums;

enum FindingStatus: string
{
    use EnumValues;

    case Open = 'open';
    case Dismissed = 'dismissed';
    case Disputed = 'disputed';
}
