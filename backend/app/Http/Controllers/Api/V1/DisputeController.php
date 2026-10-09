<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EscalateDisputeRequest;
use App\Http\Requests\ResolveDisputeRequest;
use App\Http\Requests\StoreDisputeEventRequest;
use App\Http\Requests\StoreDisputeRequest;
use App\Http\Requests\SubmitDisputeRequest;
use App\Http\Requests\UpdateDisputeRequest;
use App\Http\Resources\DisputeListResource;
use App\Http\Resources\DisputeResource;
use App\Models\Bill;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $disputes = Dispute::query()
            ->ownedBy($request->user())
            ->with('bill.property')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return DisputeListResource::collection($disputes);
    }

    public function store(StoreDisputeRequest $request, Bill $bill): JsonResponse
    {
        $dispute = $this->disputes->create($bill, $request->user(), $request->validated('finding_ids'));

        return (new DisputeResource($this->forResponse($dispute)))->response()->setStatusCode(201);
    }

    public function show(Dispute $dispute): DisputeResource
    {
        return new DisputeResource($this->forResponse($dispute));
    }

    public function update(UpdateDisputeRequest $request, Dispute $dispute): DisputeResource
    {
        return new DisputeResource($this->forResponse($this->disputes->updateLetter($dispute, $request->validated())));
    }

    public function destroy(Dispute $dispute): Response
    {
        $this->disputes->delete($dispute);

        return response()->noContent();
    }

    public function submit(SubmitDisputeRequest $request, Dispute $dispute): DisputeResource
    {
        return new DisputeResource($this->forResponse($this->disputes->submit($dispute, $request->validated())));
    }

    public function addEvent(StoreDisputeEventRequest $request, Dispute $dispute): DisputeResource
    {
        return new DisputeResource($this->forResponse($this->disputes->addEvent($dispute, $request->validated())));
    }

    public function escalate(EscalateDisputeRequest $request, Dispute $dispute): DisputeResource
    {
        return new DisputeResource($this->forResponse($this->disputes->escalate($dispute, $request->validated('note'))));
    }

    public function resolve(ResolveDisputeRequest $request, Dispute $dispute): DisputeResource
    {
        return new DisputeResource($this->forResponse($this->disputes->resolve($dispute, $request->validated())));
    }

    private function forResponse(Dispute $dispute): Dispute
    {
        return $dispute->fresh(['bill.property', 'events']);
    }
}
