<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBillRequest;
use App\Http\Requests\UpdateBillRequest;
use App\Http\Resources\BillListResource;
use App\Http\Resources\BillResource;
use App\Models\Bill;
use App\Models\Property;
use App\Services\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillController extends Controller
{
    public function __construct(private readonly BillService $bills) {}

    public function index(Property $property): AnonymousResourceCollection
    {
        $bills = $property->bills()
            ->with('findings')
            // Newest bill_date first, nulls last.
            ->orderByRaw('bill_date IS NULL')
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Bill $bill) => $bill->setRelation('property', $property));

        return BillListResource::collection($bills);
    }

    public function store(StoreBillRequest $request, Property $property): JsonResponse
    {
        $bill = $this->bills->create($property, $request->user(), $request->billData(), $request->file('file'));

        return (new BillResource($this->forResponse($bill)))->response()->setStatusCode(201);
    }

    public function show(Bill $bill): BillResource
    {
        return new BillResource($this->forResponse($bill));
    }

    public function update(UpdateBillRequest $request, Bill $bill): BillResource
    {
        $bill = $this->bills->update($bill, $request->billData());

        return new BillResource($this->forResponse($bill));
    }

    public function audit(Bill $bill, AuditService $audit): BillResource
    {
        $audit->audit($bill);

        return new BillResource($this->forResponse($bill));
    }

    public function file(Request $request, Bill $bill): StreamedResponse
    {
        $disk = Storage::disk(BillService::DISK);
        if (! $bill->hasFile() || ! $disk->exists($bill->file_path)) {
            abort(404);
        }

        $extension = pathinfo($bill->file_path, PATHINFO_EXTENSION);

        return $disk->response($bill->file_path, 'bill-'.$bill->id.($extension !== '' ? '.'.$extension : ''), [
            'Content-Type' => $bill->file_mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Bill $bill): Response
    {
        $this->bills->delete($bill);

        return response()->noContent();
    }

    private function forResponse(Bill $bill): Bill
    {
        return $bill->fresh(['property', 'lineItems', 'findings']);
    }
}
