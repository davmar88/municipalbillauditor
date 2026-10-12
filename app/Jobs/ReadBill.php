<?php

namespace App\Jobs;

use App\Enums\BillStatus;
use App\Enums\OrganisationKind;
use App\Models\Bill;
use App\Models\Property;
use App\Services\AuditService;
use App\Services\BillChecker;
use App\Services\BillWriter;
use App\Services\Claude\ClaudeCosts;
use App\Services\CustomerMail;
use App\Services\Disputes\DisputeApproval;
use App\Services\Municipalities\MunicipalityDirectory;
use App\Services\Reading\BillReader;
use App\Services\Reading\BillReadingFailed;
use App\Services\Reading\ExtractedBill;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads an uploaded or forwarded bill, matches it to a property and checks
 * it. This is the paid bill check: it is charged before the bill is read,
 * waits if there isn't enough credit, and is refunded if the read fails.
 */
class ReadBill implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public Bill $bill) {}

    public function handle(BillReader $reader, BillWriter $writer, AuditService $audit, MunicipalityDirectory $directory, BillChecker $checker, CustomerMail $mail, ClaudeCosts $costs): void
    {
        $bill = $this->bill;

        if (! in_array($bill->status, [BillStatus::Received, BillStatus::Reading], true) || $bill->file_path === null) {
            return;
        }

        if (! $checker->pay($bill)) {
            return;
        }

        $bill->update(['status' => BillStatus::Reading]);

        try {
            $contents = Storage::disk(config('auditor.bill_disk'))->get($bill->file_path);
            $read = $reader->read((string) $contents, (string) $bill->file_mime);
        } catch (BillReadingFailed $e) {
            if ($e->usage !== null) {
                $costs->record($e->usage, $bill->organisation, $bill, 'bill_read');
            }

            $bill->update(['status' => BillStatus::NeedsEntry, 'review_note' => $e->getMessage()]);
            $checker->refund($bill, "Bill check refunded: we couldn't read the bill automatically");

            return;
        }

        if ($read->usage !== null) {
            $costs->record($read->usage, $bill->organisation, $bill, 'bill_read');
        }

        $writer->applyExtraction($bill, $read);
        $property = $this->propertyFor($bill, $read, $directory);

        if ($property === null) {
            $bill->update(['status' => BillStatus::NeedsProperty]);

            return;
        }

        $bill->property()->associate($property)->save();
        $audit->run($bill);
        $mail->billChecked($bill->refresh());
        app(DisputeApproval::class)->autoSend($bill);
    }

    public function failed(?Throwable $exception): void
    {
        $this->bill->update([
            'status' => BillStatus::NeedsEntry,
            'review_note' => 'Something went wrong reading this bill, so it needs its details typed in.',
        ]);

        app(BillChecker::class)->refund($this->bill, 'Bill check refunded: something went wrong reading the bill');
    }

    private function propertyFor(Bill $bill, ExtractedBill $read, MunicipalityDirectory $directory): ?Property
    {
        $organisation = $bill->organisation;
        $property = $bill->property;

        if ($property === null && $read->accountNumber !== null) {
            $digits = preg_replace('/\D/', '', $read->accountNumber);

            $property = $organisation->properties()
                ->whereNotNull('account_number')
                ->get()
                ->first(fn (Property $p) => $digits !== '' && preg_replace('/\D/', '', (string) $p->account_number) === $digits);
        }

        if ($property === null && $organisation->kind === OrganisationKind::Household && $organisation->properties()->count() === 1) {
            $property = $organisation->properties()->first();
        }

        if ($property !== null) {
            // Fill in what the property record is missing, never overwrite it.
            $property->fill(array_filter([
                'account_number' => $property->account_number ?? $read->accountNumber,
                'address' => $property->address ?? $read->propertyAddress,
                'suburb' => $property->suburb ?? $read->suburb,
                // The customer never has to know who bills them: the name on
                // the bill decides. A name two municipalities share is left
                // for the customer to choose.
                'municipality_id' => $property->municipality_id ?? $directory->match(
                    $read->municipality,
                    implode(' ', array_filter([$read->propertyAddress, $read->suburb, $read->municipalityAddress])),
                )?->id,
            ], fn ($value) => $value !== null))->save();
        }

        return $property;
    }
}
