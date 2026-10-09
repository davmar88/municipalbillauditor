<?php

namespace App\Services;

use App\Http\Resources\BillResource;
use App\Http\Resources\DisputeResource;
use App\Http\Resources\OutageResource;
use App\Http\Resources\PropertyResource;
use App\Http\Resources\UserResource;
use App\Models\Bill;
use App\Models\Dispute;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * POPIA data subject rights: export everything, delete everything.
 */
class AccountService
{
    /**
     * @return array<string, mixed>
     */
    public function export(User $user, Request $request): array
    {
        $properties = Property::query()
            ->ownedBy($user)
            ->withSummaryCounts()
            ->with([
                'bills' => fn ($q) => $q->with(['lineItems', 'findings']),
                'outages' => fn ($q) => $q->orderBy('starts_at'),
            ])
            ->orderBy('id')
            ->get();

        $disputes = Dispute::query()
            ->ownedBy($user)
            ->with(['bill.property', 'events'])
            ->orderBy('id')
            ->get();

        return [
            'exported_at' => now()->toISOString(),
            'user' => (new UserResource($user))->resolve($request),
            'properties' => $properties->map(function (Property $property) use ($request) {
                $property->bills->each(fn (Bill $bill) => $bill->setRelation('property', $property));

                return (new PropertyResource($property))->resolve($request) + [
                    'bills' => BillResource::collection($property->bills->sortBy('id')->values())->resolve($request),
                    'outages' => OutageResource::collection($property->outages)->resolve($request),
                ];
            })->all(),
            'disputes' => DisputeResource::collection($disputes)->resolve($request),
        ];
    }

    /**
     * Deletes the user, every related row, every stored bill file and all
     * tokens.
     */
    public function delete(User $user): void
    {
        $paths = Bill::query()->ownedBy($user)->whereNotNull('file_path')->pluck('file_path')->all();

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            // Properties, bills, line items, findings, outages, disputes and
            // dispute events are removed by ON DELETE CASCADE.
            $user->delete();
        });

        if ($paths !== []) {
            Storage::disk(BillService::DISK)->delete($paths);
        }
    }
}
