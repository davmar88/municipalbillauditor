<?php

namespace App\Enums;

enum DisputeChannel: string
{
    use EnumValues;

    case Email = 'email';
    case Portal = 'portal';
    case WalkIn = 'walk_in';
    case Phone = 'phone';
    case Other = 'other';
}
