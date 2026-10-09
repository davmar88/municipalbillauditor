<?php

namespace App\Enums;

enum BillStatus: string
{
    use EnumValues;

    case NeedsReview = 'needs_review';
    case Extracting = 'extracting';
    case ExtractionFailed = 'extraction_failed';
    case Audited = 'audited';
}
