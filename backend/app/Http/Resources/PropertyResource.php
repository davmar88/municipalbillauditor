<?php

namespace App\Http\Resources;

use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Single-property shape: includes the full account number.
 *
 * @mixin Property
 */
class PropertyResource extends ApiResource
{
    protected bool $withAccountNumber = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'metro' => $this->metro,
        ];

        if ($this->withAccountNumber) {
            $data['account_number'] = $this->account_number;
        }

        return $data + [
            'account_number_masked' => $this->maskedAccountNumber(),
            'address' => $this->address,
            'property_type' => $this->property_type->value,
            'bills_count' => (int) ($this->bills_count ?? $this->bills()->count()),
            'open_findings_count' => (int) ($this->open_findings_count ?? 0),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
