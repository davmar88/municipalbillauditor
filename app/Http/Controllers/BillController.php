<?php

namespace App\Http\Controllers;

use App\Audit\Enums\CheckType;
use App\Enums\BillStatus;
use App\Enums\OrganisationKind;
use App\Http\Presenters\BillPresenter;
use App\Http\Presenters\PropertyPresenter;
use App\Http\Requests\StoreBillRequest;
use App\Models\Bill;
use App\Models\Municipality;
use App\Models\Property;
use App\Services\AuditService;
use App\Services\BillIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillController extends Controller
{
    public function index(Request $request): Response
    {
        $organisation = $request->user()->organisation;

        $bills = $organisation->bills()
            ->with(['property', 'findings', 'dispute.property.municipality'])
            ->orderByRaw('bill_date IS NULL DESC')
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('bills/index', [
            'bills' => $bills->map(fn (Bill $bill) => BillPresenter::summary($bill))->values(),
            'properties' => $organisation->properties()->orderBy('name')->orderBy('unit')->get()
                ->map(fn (Property $p) => PropertyPresenter::option($p))->values(),
            'municipalities' => Municipality::options(),
            'askMunicipality' => $organisation->kind === OrganisationKind::Household && ! $organisation->properties()->exists(),
            'checks' => array_map(fn (CheckType $c) => ['value' => $c->value, 'label' => $c->label()], CheckType::cases()),
            'maxUploadMb' => intdiv((int) config('auditor.max_upload_kb'), 1024),
        ]);
    }

    public function store(StoreBillRequest $request, BillIntake $intake): RedirectResponse
    {
        $organisation = $request->user()->organisation;
        $property = $request->property();

        if ($property === null && $organisation->kind === OrganisationKind::Household) {
            $property = $intake->homeFor($organisation, $request->filled('municipality_id') ? Municipality::find($request->integer('municipality_id')) : null);
        }

        $bill = $intake->storeUpload($organisation, $request->file('bill'), $property, $request->user());

        return to_route('bills.show', $bill);
    }

    public function sample(Request $request, BillIntake $intake): RedirectResponse
    {
        $bill = $intake->createSample($request->user()->organisation, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Here is a sample bill, checked.']);

        return to_route('bills.show', $bill);
    }

    public function show(Bill $bill): Response
    {
        $this->authorize('view', $bill);

        return Inertia::render('bills/show', [
            'bill' => BillPresenter::detail($bill),
            'properties' => $bill->status === BillStatus::NeedsProperty
                ? $bill->organisation->properties()->orderBy('name')->get()->map(fn (Property $p) => PropertyPresenter::option($p))->values()
                : [],
            'municipalities' => $bill->property !== null && $bill->property->municipality_id === null ? Municipality::options() : [],
        ]);
    }

    public function file(Bill $bill): StreamedResponse
    {
        $this->authorize('view', $bill);
        abort_if($bill->file_path === null, 404);

        return Storage::disk(config('auditor.bill_disk'))->response($bill->file_path, $bill->file_name, [
            'Content-Type' => $bill->file_mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function meterPhotoFile(Bill $bill): StreamedResponse
    {
        $this->authorize('view', $bill);
        abort_if($bill->meter_photo_path === null, 404);

        return Storage::disk(config('auditor.bill_disk'))->response($bill->meter_photo_path, null, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function meterPhoto(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('update', $bill);
        abort_if($bill->isLocked(), 403, 'This bill is already in a dispute.');

        $request->validate([
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.config('auditor.max_upload_kb')],
        ]);

        $disk = Storage::disk(config('auditor.bill_disk'));

        if ($bill->meter_photo_path !== null) {
            $disk->delete($bill->meter_photo_path);
        }

        $bill->update(['meter_photo_path' => $request->file('photo')->store("meter-photos/{$bill->organisation_id}", config('auditor.bill_disk'))]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Meter photo added. It goes with your dispute.']);

        return back();
    }

    public function assignProperty(Request $request, Bill $bill, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $bill);
        abort_if($bill->isLocked(), 403, 'This bill is already in a dispute.');

        $request->validate([
            'property_id' => ['required', Rule::exists('properties', 'id')->where('organisation_id', $bill->organisation_id)],
        ]);

        $bill->property()->associate(Property::findOrFail($request->integer('property_id')))->save();

        if ($bill->bill_date !== null) {
            $audit->run($bill);
        }

        return to_route('bills.show', $bill);
    }

    public function recheck(Bill $bill, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $bill);
        abort_if($bill->isLocked(), 403, 'This bill is already in a dispute.');
        abort_if($bill->property_id === null || $bill->bill_date === null, 422);

        $audit->run($bill);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checked again.']);

        return back();
    }

    public function destroy(Bill $bill): RedirectResponse
    {
        $this->authorize('delete', $bill);

        // Deleting the bill deletes its file, findings and any disputes.
        $bill->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bill deleted, along with its file.']);

        return to_route('bills.index');
    }
}
