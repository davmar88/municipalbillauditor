<?php

namespace App\Services;

use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Audit\Normalize;
use App\Models\Bill;
use App\Services\Reading\ExtractedBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores what a bill says, whether it was read by Claude or typed in.
 */
class BillWriter
{
    public function applyExtraction(Bill $bill, ExtractedBill $read): void
    {
        $warnings = $read->warnings();

        DB::transaction(function () use ($bill, $read, $warnings) {
            $bill->fill([
                'bill_date' => $read->billDate,
                'due_date' => $read->dueDate,
                'period_start' => $read->periodStart,
                'period_end' => $read->periodEnd,
                'account_number' => $read->accountNumber,
                'municipality_name' => $read->municipality,
                'municipality_email' => $read->municipalityEmail,
                'municipality_address' => $read->municipalityAddress,
                'municipality_phone' => $read->municipalityPhone,
                'tariff_label' => $read->tariffLabel,
                'tariff_category' => Normalize::tariff($read->tariffLabel),
                'total_due' => $read->totalDue,
                'read_method' => 'claude',
                'extraction' => $read->raw,
                'needs_review' => $warnings !== [],
                'review_note' => $warnings === [] ? null : Str::limit(implode(' ', $warnings), 250),
            ])->save();

            $this->replaceLines($bill, $read->readings, $read->charges);
        });
    }

    /**
     * @param  array{bill_date: string, due_date?: ?string, period_start?: ?string, period_end?: ?string, account_number?: ?string, tariff_label?: ?string, total_due?: ?string, readings?: list<array<string, mixed>>, charges?: list<array<string, mixed>>}  $data
     */
    public function applyManual(Bill $bill, array $data): void
    {
        $readings = array_map(fn (array $row) => [
            'service' => Service::from($row['service']),
            'meter_number' => $row['meter_number'] ?? null,
            'previous' => $row['previous'] ?? null,
            'current' => $row['current'] ?? null,
            'consumption' => $row['consumption'] ?? null,
            'unit' => $row['unit'] ?? null,
            'reading_type' => ReadingType::from($row['reading_type']),
        ], $data['readings'] ?? []);

        $charges = array_map(fn (array $row) => [
            'service' => Service::from($row['service']),
            'description' => $row['description'],
            'amount' => $row['amount'],
            'quantity' => $row['quantity'] ?? null,
            'unit' => $row['unit'] ?? null,
        ], $data['charges'] ?? []);

        DB::transaction(function () use ($bill, $data, $readings, $charges) {
            $bill->fill([
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'] ?? null,
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'tariff_label' => $data['tariff_label'] ?? null,
                'tariff_category' => Normalize::tariff($data['tariff_label'] ?? null),
                'total_due' => $data['total_due'] ?? null,
                'read_method' => 'manual',
                'needs_review' => false,
                'review_note' => null,
            ])->save();

            $this->replaceLines($bill, $readings, $charges);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $readings
     * @param  list<array<string, mixed>>  $charges
     */
    private function replaceLines(Bill $bill, array $readings, array $charges): void
    {
        $bill->readings()->delete();
        $bill->charges()->delete();

        foreach ($readings as $reading) {
            $bill->readings()->create([
                ...$reading,
                'unit' => $reading['unit'] ?? $reading['service']->defaultUnit() ?: null,
            ]);
        }

        foreach ($charges as $charge) {
            $bill->charges()->create($charge);
        }

        $bill->unsetRelation('readings')->unsetRelation('charges');
    }
}
