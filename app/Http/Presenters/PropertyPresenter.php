<?php

namespace App\Http\Presenters;

use App\Models\Property;

class PropertyPresenter
{
    /**
     * @return array{id: int, label: string, metro: ?string}
     */
    public static function option(Property $property): array
    {
        return [
            'id' => $property->id,
            'label' => $property->label(),
            'metro' => $property->relationLoaded('metro') ? $property->metro?->short_name : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Property $property): array
    {
        return [
            'id' => $property->id,
            'label' => $property->label(),
            'name' => $property->name,
            'unit' => $property->unit,
            'address' => $property->address,
            'suburb' => $property->suburb,
            'metroId' => $property->metro_id,
            'metro' => $property->metro?->short_name,
            'propertyType' => $property->property_type->value,
            'propertyTypeLabel' => $property->property_type->label(),
            'accountNumber' => $property->account_number,
            'billCount' => $property->bills_count ?? null,
        ];
    }
}
