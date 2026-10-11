<?php

namespace App\Http\Controllers;

use App\Audit\Enums\ReadingType;
use App\Audit\Enums\Service;
use App\Http\Presenters\PropertyPresenter;
use App\Models\Bill;
use App\Models\Property;
use App\Services\AuditService;
use App\Services\BillWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Typing in or correcting what a bill says, when it couldn't be read
 * automatically or was read wrongly.
 */
class BillDetailsController extends Controller
{
    public function edit(Bill $bill): Response
    {
        $this->authorize('update', $bill);
        abort_if($bill->isLocked(), 403, 'This bill is already in a dispute.');
        $bill->load(['readings', 'charges', 'property']);

        return Inertia::render('bills/details', [
            'bill' => [
                'id' => $bill->id,
                'month' => $bill->monthLabel(),
                'status' => $bill->status->value,
                'reviewNote' => $bill->review_note,
                'file' => $bill->file_path ? ['name' => $bill->file_name, 'url' => route('bills.file', $bill), 'mime' => $bill->file_mime] : null,
                'propertyId' => $bill->property_id,
                'billDate' => $bill->bill_date?->toDateString(),
                'dueDate' => $bill->due_date?->toDateString(),
                'periodStart' => $bill->period_start?->toDateString(),
                'periodEnd' => $bill->period_end?->toDateString(),
                'accountNumber' => $bill->account_number,
                'tariffLabel' => $bill->tariff_label,
                'totalDue' => $bill->total_due,
                'readings' => $bill->readings->map(fn ($r) => [
                    'service' => $r->service->value,
                    'meter_number' => $r->meter_number,
                    'previous' => $r->previous,
                    'current' => $r->current,
                    'consumption' => $r->consumption,
                    'unit' => $r->unit,
                    'reading_type' => $r->reading_type->value,
                ])->values(),
                'charges' => $bill->charges->map(fn ($c) => [
                    'service' => $c->service->value,
                    'description' => $c->description,
                    'amount' => $c->amount,
                    'quantity' => $c->quantity,
                    'unit' => $c->unit,
                ])->values(),
            ],
            'properties' => $bill->property_id === null
                ? $bill->organisation->properties()->orderBy('name')->get()->map(fn (Property $p) => PropertyPresenter::option($p))->values()
                : [],
            'services' => array_map(fn (Service $s) => ['value' => $s->value, 'label' => $s->label()], Service::cases()),
            'readingTypes' => array_map(fn (ReadingType $t) => ['value' => $t->value, 'label' => $t->label()], ReadingType::cases()),
        ]);
    }

    public function update(Request $request, Bill $bill, BillWriter $writer, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $bill);
        abort_if($bill->isLocked(), 403, 'This bill is already in a dispute.');

        $data = $request->validate([
            'property_id' => [Rule::requiredIf($bill->property_id === null), 'nullable', Rule::exists('properties', 'id')->where('organisation_id', $bill->organisation_id)],
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date', 'required_with:period_end'],
            'period_end' => ['nullable', 'date', 'required_with:period_start', 'after_or_equal:period_start'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'tariff_label' => ['nullable', 'string', 'max:100'],
            'total_due' => ['nullable', 'decimal:0,2', 'between:-99999999,99999999'],
            'readings' => ['array', 'max:10'],
            'readings.*.service' => ['required', Rule::in(['water', 'electricity', 'other'])],
            'readings.*.meter_number' => ['nullable', 'string', 'max:40'],
            'readings.*.previous' => ['nullable', 'decimal:0,3', 'min:0'],
            'readings.*.current' => ['nullable', 'decimal:0,3', 'min:0'],
            'readings.*.consumption' => ['nullable', 'decimal:0,3', 'min:0'],
            'readings.*.unit' => ['nullable', 'string', 'max:10'],
            'readings.*.reading_type' => ['required', Rule::enum(ReadingType::class)],
            'charges' => ['array', 'max:40'],
            'charges.*.service' => ['required', Rule::enum(Service::class)],
            'charges.*.description' => ['required', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'decimal:0,2', 'between:-99999999,99999999'],
            'charges.*.quantity' => ['nullable', 'decimal:0,3', 'min:0'],
            'charges.*.unit' => ['nullable', 'string', 'max:10'],
        ], [
            'bill_date.required' => 'Enter the date printed on the bill.',
            'readings.*.reading_type.required' => 'Say whether the reading is actual or estimated.',
        ]);

        if ($bill->property_id === null) {
            $bill->property()->associate(Property::findOrFail($data['property_id']));
        }

        $writer->applyManual($bill, $data);
        $audit->run($bill->refresh());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved and checked.']);

        return to_route('bills.show', $bill);
    }
}
