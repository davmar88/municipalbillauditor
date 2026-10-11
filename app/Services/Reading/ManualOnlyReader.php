<?php

namespace App\Services\Reading;

/**
 * Used when no Anthropic API key is configured: every bill waits for manual
 * entry.
 */
class ManualOnlyReader implements BillReader
{
    public function read(string $contents, string $mimeType): ExtractedBill
    {
        throw new BillReadingFailed('Automatic reading is switched off, so this bill needs its details typed in.');
    }
}
