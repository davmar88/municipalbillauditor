<?php

namespace App\Http\Resources;

use App\Models\LineItem;
use Illuminate\Http\Request;

/**
 * @mixin LineItem
 */
class LineItemResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service' => $this->service->value,
            // Always a string (docs/api.md); "" when the bill line has none.
            'description' => $this->description ?? '',
            'tariff_category' => $this->tariff_category,
            'reading_type' => $this->reading_type->value,
            'previous_reading' => $this->previous_reading,
            'current_reading' => $this->current_reading,
            'consumption' => $this->consumption,
            'unit' => $this->unit?->value,
            'amount_cents' => $this->amount_cents,
        ];
    }
}
