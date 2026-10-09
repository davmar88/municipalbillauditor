<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOutageRequest;
use App\Http\Resources\OutageResource;
use App\Models\Bill;
use App\Models\Outage;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OutageController extends Controller
{
    public function index(Property $property): AnonymousResourceCollection
    {
        return OutageResource::collection(
            $property->outages()->orderByDesc('starts_at')->orderByDesc('id')->get(),
        );
    }

    public function store(StoreOutageRequest $request, Property $property, AuditService $audit): JsonResponse
    {
        $data = $request->validated();

        $outage = new Outage([
            'service' => $data['service'],
            // Stored in UTC whatever offset the client sent.
            'starts_at' => CarbonImmutable::parse($data['starts_at'])->utc(),
            'ends_at' => CarbonImmutable::parse($data['ends_at'])->utc(),
            'notes' => $data['notes'] ?? null,
        ]);
        $outage->property()->associate($property);
        $outage->save();

        $this->reauditOverlapping($audit, $property, $outage);

        return (new OutageResource($outage))->response()->setStatusCode(201);
    }

    public function destroy(Outage $outage, AuditService $audit): Response
    {
        $property = $outage->property;
        $outage->delete();

        $this->reauditOverlapping($audit, $property, $outage);

        return response()->noContent();
    }

    /**
     * Re-audits the property's bills whose period overlaps the outage.
     */
    private function reauditOverlapping(AuditService $audit, Property $property, Outage $outage): void
    {
        $timezone = config('audit.timezone', 'UTC');
        $outageStart = $outage->starts_at->copy()->setTimezone($timezone)->toDateString();
        $outageEnd = $outage->ends_at->copy()->setTimezone($timezone)->toDateString();

        $audit->auditProperty($property, fn (Bill $bill) => $bill->period_start !== null
            && $bill->period_end !== null
            && $bill->period_start->toDateString() <= $outageEnd
            && $bill->period_end->toDateString() >= $outageStart);
    }
}
