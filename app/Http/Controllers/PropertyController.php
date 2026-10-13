<?php

namespace App\Http\Controllers;

use App\Audit\Enums\PropertyType;
use App\Http\Presenters\PropertyPresenter;
use App\Models\Municipality;
use App\Models\Property;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('properties/index', [
            'properties' => $request->user()->organisation->properties()
                ->with('municipality')
                ->withCount('bills')
                ->orderBy('name')
                ->orderBy('unit')
                ->get()
                ->map(fn (Property $p) => PropertyPresenter::detail($p))
                ->values(),
            'municipalities' => Municipality::options(),
            'propertyTypes' => array_map(fn (PropertyType $t) => ['value' => $t->value, 'label' => $t->label()], PropertyType::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->organisation->properties()->create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Property added.']);

        return back();
    }

    public function update(Request $request, Property $property): RedirectResponse
    {
        $this->authorize('update', $property);
        $property->update($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Property saved.']);

        return back();
    }

    /**
     * The customer tells us which municipality bills a property, when the
     * bill didn't make it clear. Its latest bill is checked again with that
     * municipality's tariffs and outages.
     */
    public function setMunicipality(Request $request, Property $property, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $property);

        $property->update($request->validate([
            'municipality_id' => ['required', Rule::exists('municipalities', 'id')->where('live', true)],
        ]));

        $bill = $property->latestBill;

        if ($bill !== null && $bill->isChecked() && ! $bill->isLocked()) {
            $audit->run($bill);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved. Disputes for this property go to '.$property->municipality->short_name.'.']);

        return back();
    }

    public function destroy(Property $property): RedirectResponse
    {
        $this->authorize('delete', $property);

        // One by one, so each bill's file is deleted with it.
        $property->bills()->get()->each->delete();
        $property->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Property and its bills deleted.']);

        return to_route('properties.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:255'],
            // Optional: the first bill tells us.
            'municipality_id' => ['nullable', Rule::exists('municipalities', 'id')],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'account_number' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
