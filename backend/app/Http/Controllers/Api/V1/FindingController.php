<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FindingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFindingRequest;
use App\Http\Resources\FindingResource;
use App\Models\Finding;
use Illuminate\Validation\ValidationException;

class FindingController extends Controller
{
    public function update(UpdateFindingRequest $request, Finding $finding): FindingResource
    {
        if ($finding->status === FindingStatus::Disputed) {
            throw ValidationException::withMessages([
                'status' => ['This finding is part of a dispute. Delete the draft dispute first if you want to change it.'],
            ]);
        }

        $finding->status = FindingStatus::from($request->validated('status'));
        $finding->save();

        return new FindingResource($finding);
    }
}
