<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Http\Resources\PropertyListResource;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Services\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $properties = Property::query()
            ->ownedBy($request->user())
            ->withSummaryCounts()
            ->orderBy('nickname')
            ->orderBy('id')
            ->get();

        return PropertyListResource::collection($properties);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = new Property($request->validated());
        $property->user()->associate($request->user());
        $property->save();

        return (new PropertyResource($property->loadSummaryCounts()))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Property $property): PropertyResource
    {
        return new PropertyResource($property->loadSummaryCounts());
    }

    public function update(UpdatePropertyRequest $request, Property $property, AuditService $audit): PropertyResource
    {
        $property->fill($request->validated());
        $typeChanged = $property->isDirty('property_type');
        $property->save();

        // The tariff rule depends on the property type.
        if ($typeChanged) {
            $audit->auditProperty($property);
        }

        return new PropertyResource($property->loadSummaryCounts());
    }

    public function destroy(Property $property): Response
    {
        $paths = $property->bills()->whereNotNull('file_path')->pluck('file_path')->all();

        // Bills, line items, findings, outages and disputes cascade.
        $property->delete();

        if ($paths !== []) {
            Storage::disk(BillService::DISK)->delete($paths);
        }

        return response()->noContent();
    }
}
