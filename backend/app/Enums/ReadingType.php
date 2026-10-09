<?php

namespace App\Enums;

enum ReadingType: string
{
    use EnumValues;

    case Actual = 'actual';
    case Estimated = 'estimated';
    case Unknown = 'unknown';
}
