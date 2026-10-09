<?php

namespace App\Http\Resources;

use App\Models\Finding;
use Illuminate\Http\Request;

/**
 * @mixin Finding
 */
class FindingResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bill_id' => $this->bill_id,
            'rule' => $this->rule->value,
            'severity' => $this->severity->value,
            'confidence' => (float) $this->confidence,
            'title' => $this->title,
            'explanation' => $this->explanation,
            'estimated_overcharge_cents' => $this->estimated_overcharge_cents,
            // Always a JSON object, even when empty.
            'evidence' => (object) ($this->evidence ?? []),
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
