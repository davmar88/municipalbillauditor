<?php

namespace App\Services\Reading;

interface BillReader
{
    /**
     * Read a photographed or PDF bill.
     *
     * @throws BillReadingFailed when the bill can't be read automatically.
     */
    public function read(string $contents, string $mimeType): ExtractedBill;
}
