<?php

namespace App\Enums;

enum BillSource: string
{
    case Upload = 'upload';
    case Email = 'email';
    case Manual = 'manual';
    case Sample = 'sample';
}
