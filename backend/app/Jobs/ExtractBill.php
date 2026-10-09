<?php

namespace App\Jobs;

use App\Enums\BillStatus;
use App\Extraction\BillExtractionFailed;
use App\Extraction\BillExtractor;
use App\Models\Bill;
use App\Services\BillService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads a bill's uploaded file with the AI extractor, fills in the bill and
 * audits it: status extracting -> audited, or extraction_failed.
 */
class ExtractBill implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $billId) {}

    public function handle(BillExtractor $extractor, BillService $bills): void
    {
        $bill = Bill::query()->with('property.user')->find($this->billId);
        if ($bill === null || $bill->status !== BillStatus::Extracting) {
            return;
        }

        // Consent or configuration may have changed since the upload.
        if (! $bill->property->user->ai_extraction_consent || ! $extractor->isAvailable()) {
            $bill->forceFill(['status' => BillStatus::NeedsReview])->save();

            return;
        }

        try {
            $contents = $bill->file_path !== null ? Storage::disk(BillService::DISK)->get($bill->file_path) : null;
            if ($contents === null) {
                throw BillExtractionFailed::because('file_missing');
            }

            $extracted = $extractor->extract($contents, (string) $bill->file_mime);
            $bills->applyExtraction($bill, $extracted);
        } catch (Throwable $e) {
            // Never log bill contents: only the bill id and a reason code.
            Log::warning('Bill extraction failed.', [
                'bill_id' => $bill->id,
                'reason' => $e instanceof BillExtractionFailed ? $e->getMessage() : class_basename($e),
            ]);
            $this->markFailed($bill);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $bill = Bill::query()->find($this->billId);
        if ($bill !== null) {
            $this->markFailed($bill);
        }
    }

    private function markFailed(Bill $bill): void
    {
        $bill->refresh();
        if ($bill->status === BillStatus::Extracting) {
            $bill->forceFill(['status' => BillStatus::ExtractionFailed])->save();
        }
    }
}
