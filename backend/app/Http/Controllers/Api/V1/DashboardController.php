<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\OverchargeTotals;
use App\Enums\DisputeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResource;
use App\Models\Dispute;
use App\Models\Finding;
use App\Models\Property;
use App\Services\DeadlineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DeadlineService $deadlines): JsonResponse
    {
        $user = $request->user();
        $counted = Finding::query()
            ->ownedBy($user)
            ->openAndCounted()
            ->get(['id', 'bill_id', 'service', 'severity', 'status', 'estimated_overcharge_cents']);

        return response()->json(['data' => [
            'properties_count' => Property::query()->ownedBy($user)->count(),
            'open_findings_count' => $counted->count(),
            'potential_overcharge_cents' => OverchargeTotals::sum($counted),
            'active_disputes_count' => Dispute::query()->ownedBy($user)
                ->whereIn('status', array_map(fn (DisputeStatus $s) => $s->value, DisputeStatus::active()))
                ->count(),
            'recovered_cents' => (int) Dispute::query()->ownedBy($user)
                ->where('status', DisputeStatus::Resolved->value)
                ->sum('outcome_amount_cents'),
            'upcoming_deadlines' => $deadlines->upcoming($user, 20)->all(),
        ]], 200, [], ApiResource::JSON_OPTIONS);
    }
}
