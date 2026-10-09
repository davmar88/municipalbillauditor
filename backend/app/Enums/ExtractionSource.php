<?php

namespace App\Enums;

enum ExtractionSource: string
{
    use EnumValues;

    case Manual = 'manual';
    case Ai = 'ai';
}
