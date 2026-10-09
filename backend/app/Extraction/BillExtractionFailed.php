<?php

namespace App\Extraction;

use RuntimeException;

/**
 * Extraction could not produce usable data. The message is a short reason
 * code and never contains bill contents.
 */
class BillExtractionFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
