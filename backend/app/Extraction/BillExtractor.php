<?php

namespace App\Extraction;

/**
 * Reads a photo or PDF of a municipal bill and returns structured fields.
 */
interface BillExtractor
{
    /**
     * Whether extraction is configured on this server (e.g. an API key is set).
     */
    public function isAvailable(): bool;

    /**
     * @param  string  $contents  raw file bytes
     * @param  string  $mimeType  e.g. image/jpeg or application/pdf
     *
     * @throws BillExtractionFailed
     */
    public function extract(string $contents, string $mimeType): ExtractedBill;
}
