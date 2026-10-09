<?php

namespace App\Services;

use App\Audit\AuditService;
use App\Enums\BillStatus;
use App\Enums\ExtractionSource;
use App\Extraction\BillExtractionFailed;
use App\Extraction\BillExtractor;
use App\Extraction\ExtractedBill;
use App\Http\Requests\Concerns\LineItemRules;
use App\Jobs\ExtractBill;
use App\Models\Bill;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class BillService
{
    public const DISK = 'bills';

    private const DATE_FIELDS = ['bill_date', 'period_start', 'period_end', 'due_date'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly BillExtractor $extractor,
    ) {}

    /**
     * Bill creation flow (docs/api.md):
     * 1. line items supplied -> audited now (manual);
     * 2. only a file, AI configured and the user opted in -> extracting;
     * 3. only a file otherwise -> needs_review.
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(Property $property, User $user, array $data, ?UploadedFile $file): Bill
    {
        $lineItems = array_values($data['line_items'] ?? []);

        $path = null;
        if ($file !== null) {
            // Random file name on the private disk; nothing about the user in it.
            $path = $file->store('', self::DISK);
            if ($path === false) {
                throw new RuntimeException('The bill file could not be stored.');
            }
        }

        $status = match (true) {
            $lineItems !== [] => BillStatus::Audited,
            $file !== null && $user->ai_extraction_consent && $this->extractor->isAvailable() => BillStatus::Extracting,
            default => BillStatus::NeedsReview,
        };

        try {
            $bill = DB::transaction(function () use ($property, $data, $file, $path, $status, $lineItems) {
                $bill = new Bill;
                $bill->property()->associate($property);
                foreach ([...self::DATE_FIELDS, 'total_cents'] as $field) {
                    $bill->{$field} = $data[$field] ?? null;
                }
                $bill->status = $status;
                $bill->extraction_source = ExtractionSource::Manual;
                $bill->file_path = $path ?: null;
                $bill->file_mime = $file?->getMimeType();
                $bill->save();

                $this->createLineItems($bill, $lineItems);

                return $bill;
            });
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }

        if ($status === BillStatus::Audited) {
            $this->audit->auditProperty($property);
        } elseif ($status === BillStatus::Extracting) {
            // Runs inline when QUEUE_CONNECTION=sync.
            ExtractBill::dispatch($bill->id);
        }

        return $bill->refresh();
    }

    /**
     * PUT /bills/{id}: updates the given fields, replaces all line items
     * and re-audits. extraction_source is left unchanged.
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(Bill $bill, array $data): Bill
    {
        DB::transaction(function () use ($bill, $data) {
            foreach ([...self::DATE_FIELDS, 'total_cents'] as $field) {
                if (array_key_exists($field, $data)) {
                    $bill->{$field} = $data[$field];
                }
            }
            $bill->status = BillStatus::Audited;
            $bill->save();

            $bill->lineItems()->delete();
            $this->createLineItems($bill, array_values($data['line_items']));
        });

        $this->audit->auditProperty($bill->property);

        return $bill->refresh();
    }

    /**
     * Applies AI extraction results: fills fields the user left empty,
     * creates the line items and audits.
     */
    public function applyExtraction(Bill $bill, ExtractedBill $extracted): Bill
    {
        $items = [];
        foreach ($extracted->lineItems as $item) {
            $validator = Validator::make($item, LineItemRules::rules(''));
            if ($validator->passes()) {
                $items[] = $validator->validated();
            }
        }

        if ($items === []) {
            throw BillExtractionFailed::because('no_line_items');
        }

        DB::transaction(function () use ($bill, $extracted, $items) {
            $values = [
                'bill_date' => $extracted->billDate,
                'period_start' => $extracted->periodStart,
                'period_end' => $extracted->periodEnd,
                'due_date' => $extracted->dueDate,
                'total_cents' => $extracted->totalCents,
            ];
            foreach ($values as $field => $value) {
                // What the user typed wins over what was read from the file.
                if ($bill->{$field} === null && $value !== null) {
                    $bill->{$field} = $value;
                }
            }
            if ($bill->period_start !== null && $bill->period_end !== null && $bill->period_end->lt($bill->period_start)) {
                $bill->period_start = null;
                $bill->period_end = null;
            }

            $bill->extraction_source = ExtractionSource::Ai;
            $bill->status = BillStatus::Audited;
            $bill->save();

            $bill->lineItems()->delete();
            $this->createLineItems($bill, $items);
        });

        $this->audit->auditProperty($bill->property);

        return $bill->refresh();
    }

    /**
     * Deletes the bill, its file, line items, findings and disputes, then
     * re-audits the property's other bills (their history changed).
     */
    public function delete(Bill $bill): void
    {
        $property = $bill->property;
        $path = $bill->file_path;

        $bill->delete();

        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }

        $this->audit->auditProperty($property);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function createLineItems(Bill $bill, array $items): void
    {
        foreach ($items as $item) {
            $bill->lineItems()->create(LineItemRules::normalise($item));
        }
        $bill->unsetRelation('lineItems');
    }
}
